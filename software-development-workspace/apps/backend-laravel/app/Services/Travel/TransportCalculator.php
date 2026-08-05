<?php

namespace App\Services\Travel;

use App\Models\Travel\TransportBusFare;
use App\Models\Travel\TransportMileageRate;
use App\Models\Travel\TravelTransportLeg;
use RuntimeException;

/**
 * What a journey costs.
 *
 * Transport is computed rather than looked up: a 40 km errand and a 600 km
 * round trip are not the same entitlement, and no grade table separates them.
 * Each mode has its own arithmetic, and two of them deliberately have none.
 *
 * A taxi fare is not knowable in advance. Producing an estimate for one gives
 * the approver a number they cannot judge and the traveller one they cannot
 * meet, so those modes carry an intent to claim and settle on evidence.
 */
class TransportCalculator
{
    public const MODES = ['self_drive', 'hired_vehicle', 'bus', 'taxi', 'flight', 'visa'];

    /**
     * Cost a leg, writing back the figures the calculation used.
     *
     * The rate is snapshotted onto the leg for the same reason allowance rates
     * are snapshotted onto an expense line: reconciling months later against
     * whatever the rate is by then would re-judge a decision already made.
     *
     * @return array{amount: float|null, basis: string}
     */
    public function cost(TravelTransportLeg $leg): array
    {
        return match ($leg->mode) {
            'self_drive'    => $this->selfDrive($leg),
            'hired_vehicle' => $this->hiredVehicle($leg),
            'bus'           => $this->bus($leg),
            // Entered, not derived. Both are quotes or actuals the requester
            // holds; the system's job is to record and reconcile them.
            'taxi', 'flight', 'visa' => [
                'amount' => $leg->estimated_cost !== null ? (float) $leg->estimated_cost : null,
                'basis'  => $leg->mode === 'taxi'
                    ? 'Actual cost, settled on receipts'
                    : 'Quoted cost',
            ],
            default => throw new RuntimeException("Unknown transport mode: {$leg->mode}"),
        };
    }

    /**
     * Self-drive: distance at the approved rate per kilometre.
     *
     * The rate covers fuel and wear together, which is why fuel price does not
     * enter here — paying both would reimburse the same litre twice.
     */
    private function selfDrive(TravelTransportLeg $leg): array
    {
        $km = (float) ($leg->actual_km ?? $leg->estimated_km ?? 0);

        if ($km <= 0) {
            return ['amount' => null, 'basis' => 'No distance entered'];
        }

        $rate = $leg->rate_per_km_applied !== null
            ? (float) $leg->rate_per_km_applied
            : $this->mileageRate($leg);

        if ($rate === null) {
            return ['amount' => null, 'basis' => 'No mileage rate is configured'];
        }

        return [
            'amount' => round($km * $rate, 2),
            'basis'  => number_format($km, 1) . ' km at ' . number_format($rate, 2) . '/km',
        ];
    }

    /**
     * Hired vehicle: the fuel the journey will burn.
     *
     * Consumption comes from the vehicle class rather than the individual
     * vehicle: real figures vary more by driver and load than by chassis, and
     * an organisation that cannot maintain per-vehicle data ends up with one
     * stale row per car instead of one good row per class.
     */
    private function hiredVehicle(TravelTransportLeg $leg): array
    {
        $km = (float) ($leg->actual_km ?? $leg->estimated_km ?? 0);
        $kmPerLitre = (float) ($leg->vehicleType?->km_per_litre ?? 0);
        $fuelPrice  = (float) ($leg->fuel_price_per_litre ?? 0);

        if ($km <= 0) {
            return ['amount' => null, 'basis' => 'No distance entered'];
        }

        if ($kmPerLitre <= 0) {
            return ['amount' => null, 'basis' => 'The selected vehicle type has no fuel consumption set'];
        }

        if ($fuelPrice <= 0) {
            // Asked for at the point of request precisely because it moves.
            return ['amount' => null, 'basis' => 'Enter the current fuel price'];
        }

        $litres = $km / $kmPerLitre;

        return [
            'amount' => round($litres * $fuelPrice, 2),
            'basis'  => number_format($km, 1) . ' km ÷ ' . number_format($kmPerLitre, 1)
                . ' km/l = ' . number_format($litres, 1) . ' l at ' . number_format($fuelPrice, 2),
        ];
    }

    /**
     * Bus: the published round-trip fare for the route.
     *
     * A fare is a price, not a function of distance. Computing one produces a
     * figure no ticket office recognises.
     */
    private function bus(TravelTransportLeg $leg): array
    {
        if (! $leg->travel_destination_id) {
            return ['amount' => null, 'basis' => 'Select a destination'];
        }

        $fare = TransportBusFare::withoutGlobalScopes()
            ->where('organization_id', $leg->request->organization_id)
            ->where('travel_destination_id', $leg->travel_destination_id)
            ->where('is_active', true)
            ->first();

        if (! $fare) {
            return ['amount' => null, 'basis' => 'No standard fare is set for this route'];
        }

        return [
            'amount' => (float) $fare->round_trip_amount,
            'basis'  => 'Standard round-trip fare',
        ];
    }

    /**
     * Difference between what was asked for and what the trip actually was.
     *
     * Mileage modes reconcile against distance, not against a receipt alone: a
     * fuel receipt proves money was spent, not that the journey happened.
     * Actual kilometres is the one number the driver always knows.
     *
     * @return array{estimated: ?float, actual: ?float, variance: ?float, basis: string}
     */
    public function reconcile(TravelTransportLeg $leg): array
    {
        $estimated = $leg->estimated_cost !== null ? (float) $leg->estimated_cost : null;

        // For mileage modes the actual is recomputed from the distance driven,
        // at the rate the request was approved against.
        $actual = match ($leg->mode) {
            'self_drive', 'hired_vehicle' => $leg->actual_km !== null
                ? $this->cost($leg)['amount']
                : ($leg->actual_cost !== null ? (float) $leg->actual_cost : null),
            default => $leg->actual_cost !== null ? (float) $leg->actual_cost : null,
        };

        return [
            'estimated' => $estimated,
            'actual'    => $actual,
            'variance'  => $estimated !== null && $actual !== null
                ? round($actual - $estimated, 2)
                : null,
            'basis'     => $this->cost($leg)['basis'],
        ];
    }

    /** The rate in force on the day the journey starts. */
    private function mileageRate(TravelTransportLeg $leg): ?float
    {
        $on = $leg->request->departure_date?->toDateString() ?? now()->toDateString();

        $rate = TransportMileageRate::withoutGlobalScopes()
            ->where('organization_id', $leg->request->organization_id)
            ->whereDate('effective_from', '<=', $on)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $on))
            ->orderByDesc('effective_from')
            ->first();

        return $rate ? (float) $rate->rate_per_km : null;
    }
}
