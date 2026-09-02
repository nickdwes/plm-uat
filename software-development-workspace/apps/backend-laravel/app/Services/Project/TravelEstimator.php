<?php

namespace App\Services\Project;

use App\Models\Project\Project;
use App\Models\Travel\TravelAllowanceRate;
use Illuminate\Support\Carbon;

/**
 * A rate-card estimate for a project's travel cycles.
 *
 * Per-diem, accommodation and incidentals are priced from the organisation's
 * own allowance rate card (the default, applies-to-all rows in force today), so
 * an estimate tracks whatever Finance has configured. Transport is deliberately
 * absent from that card — the travel module answers it per leg — so a cycle,
 * which has a mode but no leg detail, is priced from standard per-mode figures
 * instead. Every rate used is returned alongside the numbers, so an estimate is
 * never a black box.
 */
class TravelEstimator
{
    /** Fallbacks when the allowance card has no default row (KES, per day / per trip). */
    private const FALLBACK = ['per_diem' => 4500, 'accommodation' => 12000, 'incidentals' => 5000];

    /** Round-trip transport per person by cycle mode (KES) — the card omits transport by design. */
    private const TRANSPORT = ['air' => 25000, 'inter_city' => 5000, 'road' => 4000, 'local' => 1000, 'default' => 4000];

    public function __construct(private TravelCycleBuilder $builder)
    {
    }

    /** Per-cycle estimates for the whole project, plus the rate basis and total. */
    public function forProject(Project $project): array
    {
        $rates  = $this->rates();
        $cycles = collect($this->builder->build($project))
            ->map(fn ($c) => $this->estimateCycle($c, $rates))
            ->values();

        return [
            'rates'  => $rates,
            'cycles' => $cycles,
            'total'  => round((float) $cycles->sum('total'), 2),
        ];
    }

    /** The rate basis: allowance-card rows where present, fallbacks otherwise. */
    public function rates(): array
    {
        $today = Carbon::now()->toDateString();

        return [
            'currency'      => 'KES',
            'per_diem'      => $this->cardRate('per diem', $today),
            'accommodation' => $this->cardRate('accommodation', $today),
            'incidentals'   => $this->cardRate('incidental', $today),
            'transport'     => self::TRANSPORT,
        ];
    }

    /** One estimate line, broken out so the total is auditable. */
    private function estimateCycle(array $c, array $rates): array
    {
        $people = max(1, count($c['team'] ?? []));
        $days   = (int) ($c['duration_days'] ?? $c['travel_days'] ?? 1) ?: 1;
        $nights = ! empty($c['accommodation']) ? max(1, $days - 1) : 0;
        $mode   = $c['modes'][0] ?? null;

        $perDiem     = $rates['per_diem']['amount'] * $days * $people;
        $accom       = $rates['accommodation']['amount'] * $nights * $people;
        $incidentals = $rates['incidentals']['amount'] * $people;          // per trip, per person
        $transport   = (self::TRANSPORT[$mode] ?? self::TRANSPORT['default']) * $people;

        return [
            'cycle_key'     => $c['key'],
            'number'        => $c['number'],
            'location'      => $c['location'],
            'people'        => $people,
            'days'          => $days,
            'nights'        => $nights,
            'mode'          => $mode,
            'per_diem'      => round($perDiem, 2),
            'accommodation' => round($accom, 2),
            'incidentals'   => round($incidentals, 2),
            'transport'     => round($transport, 2),
            'total'         => round($perDiem + $accom + $incidentals + $transport, 2),
        ];
    }

    /**
     * The default (applies-to-all) allowance rate for a category whose name
     * matches, in force today. Falls back to a standard figure and says so.
     */
    private function cardRate(string $nameLike, string $today): array
    {
        $row = TravelAllowanceRate::whereHas('category', fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ["%{$nameLike}%"]))
            ->whereNull('job_grade_id')->whereNull('travel_destination_id')
            ->effectiveOn($today)->orderByDesc('effective_from')->first();

        $key = match (true) {
            str_contains($nameLike, 'per diem')     => 'per_diem',
            str_contains($nameLike, 'accommodation')=> 'accommodation',
            default                                 => 'incidentals',
        };

        return $row
            ? ['amount' => (float) $row->amount, 'unit' => $row->unit, 'source' => 'rate_card']
            : ['amount' => (float) self::FALLBACK[$key], 'unit' => $key === 'incidentals' ? 'per_trip' : 'per_day', 'source' => 'default'];
    }
}
