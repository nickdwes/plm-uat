<?php

namespace Tests\Feature\Travel;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\Travel\TransportBusFare;
use App\Models\Travel\TransportMileageRate;
use App\Models\Travel\TransportVehicleType;
use App\Models\Travel\TravelDestination;
use App\Models\Travel\TravelRequest;
use App\Models\Travel\TravelTransportLeg;
use App\Services\Travel\TransportCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Transport is computed, not looked up.
 *
 * A 40 km errand and a 600 km round trip are not the same entitlement, and no
 * grade table separates them. Each mode has its own arithmetic — and two of
 * them deliberately have none, because a taxi fare is not knowable in advance
 * and inventing an estimate gives the approver a number they cannot judge.
 */
class TransportCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private TravelRequest $request;
    private TransportCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);

        $employee = Employee::create([
            'employee_number'         => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id,
            'first_name' => 'Test', 'last_name' => 'Driver',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
        ]);

        $this->request = TravelRequest::create([
            'organization_id' => $this->org->id,
            'employee_id'     => $employee->id,
            'departure_date'  => '2026-09-01',
            'return_date'     => '2026-09-02',
            'days'            => 2,
        ]);

        $this->calc = app(TransportCalculator::class);
    }

    private function leg(array $attrs): TravelTransportLeg
    {
        return $this->request->transportLegs()->create($attrs);
    }

    // ── Self-drive ────────────────────────────────────────────────────────

    public function test_self_drive_is_distance_at_the_approved_rate(): void
    {
        TransportMileageRate::create([
            'organization_id' => $this->org->id,
            'rate_per_km' => 60, 'effective_from' => '2026-01-01',
        ]);

        $leg = $this->leg(['mode' => 'self_drive', 'estimated_km' => 240]);

        $this->assertEquals(14400, $this->calc->cost($leg)['amount']);
    }

    public function test_self_drive_ignores_fuel_price(): void
    {
        // The per-kilometre rate covers fuel and wear together. Adding fuel on
        // top would reimburse the same litre twice.
        TransportMileageRate::create([
            'organization_id' => $this->org->id,
            'rate_per_km' => 60, 'effective_from' => '2026-01-01',
        ]);

        $leg = $this->leg([
            'mode' => 'self_drive', 'estimated_km' => 100, 'fuel_price_per_litre' => 195,
        ]);

        $this->assertEquals(6000, $this->calc->cost($leg)['amount']);
    }

    public function test_self_drive_uses_the_rate_in_force_when_the_trip_starts(): void
    {
        // A rate that ended in June must not price a September journey.
        TransportMileageRate::create([
            'organization_id' => $this->org->id, 'rate_per_km' => 40,
            'effective_from' => '2026-01-01', 'effective_to' => '2026-06-30',
        ]);
        TransportMileageRate::create([
            'organization_id' => $this->org->id, 'rate_per_km' => 60,
            'effective_from' => '2026-07-01',
        ]);

        $leg = $this->leg(['mode' => 'self_drive', 'estimated_km' => 100]);

        $this->assertEquals(6000, $this->calc->cost($leg)['amount']);
    }

    public function test_a_snapshotted_rate_survives_a_change_to_the_card(): void
    {
        // Reconciling months later against whatever the rate is by then would
        // re-judge a decision already made.
        TransportMileageRate::create([
            'organization_id' => $this->org->id, 'rate_per_km' => 60, 'effective_from' => '2026-01-01',
        ]);

        $leg = $this->leg([
            'mode' => 'self_drive', 'estimated_km' => 100, 'rate_per_km_applied' => 45,
        ]);

        $this->assertEquals(4500, $this->calc->cost($leg)['amount']);
    }

    public function test_self_drive_without_a_configured_rate_says_so(): void
    {
        $leg = $this->leg(['mode' => 'self_drive', 'estimated_km' => 100]);
        $result = $this->calc->cost($leg);

        $this->assertNull($result['amount']);
        $this->assertStringContainsString('mileage rate', $result['basis']);
    }

    // ── Hired vehicle ─────────────────────────────────────────────────────

    public function test_a_hired_vehicle_costs_the_fuel_it_burns(): void
    {
        $type = TransportVehicleType::create([
            'organization_id' => $this->org->id, 'name' => '4x4', 'km_per_litre' => 8,
        ]);

        $leg = $this->leg([
            'mode' => 'hired_vehicle', 'estimated_km' => 400,
            'transport_vehicle_type_id' => $type->id, 'fuel_price_per_litre' => 195,
        ]);

        // 400 km ÷ 8 km/l = 50 l at 195.
        $this->assertEquals(9750, $this->calc->cost($leg)['amount']);
    }

    public function test_a_hired_vehicle_needs_a_fuel_price_entered(): void
    {
        // Asked for at the point of request precisely because a pump price
        // held centrally is wrong within a week.
        $type = TransportVehicleType::create([
            'organization_id' => $this->org->id, 'name' => 'Saloon', 'km_per_litre' => 12,
        ]);

        $leg = $this->leg([
            'mode' => 'hired_vehicle', 'estimated_km' => 200,
            'transport_vehicle_type_id' => $type->id,
        ]);
        $result = $this->calc->cost($leg);

        $this->assertNull($result['amount']);
        $this->assertStringContainsString('fuel price', $result['basis']);
    }

    public function test_a_vehicle_type_without_consumption_cannot_be_costed(): void
    {
        $type = TransportVehicleType::create([
            'organization_id' => $this->org->id, 'name' => 'Unknown',
        ]);

        $leg = $this->leg([
            'mode' => 'hired_vehicle', 'estimated_km' => 200,
            'transport_vehicle_type_id' => $type->id, 'fuel_price_per_litre' => 195,
        ]);

        $this->assertNull($this->calc->cost($leg)['amount']);
    }

    // ── Bus ───────────────────────────────────────────────────────────────

    public function test_bus_uses_the_published_fare_for_the_route(): void
    {
        // A fare is a price, not a function of distance.
        $destination = TravelDestination::create([
            'organization_id' => $this->org->id, 'name' => 'Kisumu',
        ]);
        TransportBusFare::create([
            'organization_id' => $this->org->id,
            'travel_destination_id' => $destination->id,
            'round_trip_amount' => 3200,
        ]);

        $leg = $this->leg([
            'mode' => 'bus', 'travel_destination_id' => $destination->id, 'estimated_km' => 350,
        ]);

        $this->assertEquals(3200, $this->calc->cost($leg)['amount'], 'Distance is irrelevant to a bus fare.');
    }

    public function test_a_route_with_no_fare_set_says_so(): void
    {
        $destination = TravelDestination::create([
            'organization_id' => $this->org->id, 'name' => 'Nakuru',
        ]);

        $leg = $this->leg(['mode' => 'bus', 'travel_destination_id' => $destination->id]);

        $this->assertNull($this->calc->cost($leg)['amount']);
    }

    // ── Modes with no estimate ────────────────────────────────────────────

    public function test_a_taxi_is_recorded_rather_than_estimated(): void
    {
        // A fare nobody can predict produces a number the approver cannot
        // judge and the traveller cannot meet.
        $leg = $this->leg(['mode' => 'taxi', 'estimated_cost' => 2400]);
        $result = $this->calc->cost($leg);

        $this->assertEquals(2400, $result['amount']);
        $this->assertStringContainsString('receipts', $result['basis']);
    }

    public function test_flight_and_visa_carry_the_quoted_amount(): void
    {
        $flight = $this->leg(['mode' => 'flight', 'travel_type' => 'international', 'estimated_cost' => 78000]);
        $visa   = $this->leg(['mode' => 'visa', 'travel_type' => 'international', 'estimated_cost' => 6500]);

        $this->assertEquals(78000, $this->calc->cost($flight)['amount']);
        $this->assertEquals(6500, $this->calc->cost($visa)['amount']);
    }

    // ── Reconciliation ────────────────────────────────────────────────────

    public function test_mileage_reconciles_against_the_distance_actually_driven(): void
    {
        // A fuel receipt proves money was spent, not that the journey
        // happened. Actual kilometres is the number the driver always knows.
        TransportMileageRate::create([
            'organization_id' => $this->org->id, 'rate_per_km' => 60, 'effective_from' => '2026-01-01',
        ]);

        $leg = $this->leg([
            'mode' => 'self_drive', 'estimated_km' => 240, 'estimated_cost' => 14400,
            'rate_per_km_applied' => 60,
        ]);
        $leg->update(['actual_km' => 300]);

        $result = $this->calc->reconcile($leg->fresh());

        $this->assertEquals(18000, $result['actual']);
        $this->assertEquals(3600, $result['variance'], 'Sixty extra kilometres at the approved rate.');
    }

    public function test_a_taxi_reconciles_on_what_was_actually_paid(): void
    {
        $leg = $this->leg(['mode' => 'taxi', 'estimated_cost' => 2000]);
        $leg->update(['actual_cost' => 2650]);

        $result = $this->calc->reconcile($leg->fresh());

        $this->assertEquals(650, $result['variance']);
    }

    public function test_an_unreconciled_leg_reports_no_variance_rather_than_zero(): void
    {
        // Zero would read as "spent exactly what was approved", which is a
        // different statement from "we do not yet know".
        $leg = $this->leg(['mode' => 'taxi', 'estimated_cost' => 2000]);

        $this->assertNull($this->calc->reconcile($leg)['variance']);
    }
}
