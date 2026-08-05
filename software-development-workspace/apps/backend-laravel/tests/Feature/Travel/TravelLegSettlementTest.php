<?php

namespace Tests\Feature\Travel;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Travel\TransportMileageRate;
use App\Models\Travel\TravelRequest;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A trip does not close until its journeys are settled.
 *
 * Reconciliation covered expenses and ignored driving entirely, so a trip
 * could close with every receipt in place and a 600 km self-drive claim never
 * compared to what was actually driven. The money left against an estimate
 * nobody checked.
 */
class TravelLegSettlementTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Employee $traveller;
    private TravelRequest $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);

        TransportMileageRate::create([
            'organization_id' => $this->org->id, 'rate_per_km' => 60, 'effective_from' => '2026-01-01',
        ]);

        $this->traveller = $this->employee();

        $this->request = TravelRequest::create([
            'organization_id' => $this->org->id,
            'employee_id'     => $this->traveller->id,
            'departure_date'  => '2026-09-01',
            'return_date'     => '2026-09-02',
            'days'            => 2,
            'status'          => 'approved',
        ]);
    }

    private function employee(): Employee
    {
        return Employee::create([
            'employee_number'         => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id,
            'first_name' => 'Test', 'last_name' => 'Driver',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
        ]);
    }

    private function actingAs_(array $permissions, ?Employee $employee = null): Employee
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Test', 'scope' => 'organization', 'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();

        $employee = $employee ?? $this->employee();
        $employee->update(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        return $employee->fresh();
    }

    private function url(string $path): string
    {
        return "/api/v1/travel-requests/{$this->request->id}{$path}?org={$this->org->slug}";
    }

    private function leg(array $attrs): int
    {
        return $this->request->transportLegs()->create($attrs + ['currency' => 'KES'])->id;
    }

    public function test_a_trip_cannot_close_while_a_journey_is_unsettled(): void
    {
        $this->leg([
            'mode' => 'self_drive', 'estimated_km' => 240,
            'estimated_cost' => 14400, 'rate_per_km_applied' => 60,
        ]);

        $this->actingAs_([Permission::TRAVEL_VIEW, Permission::TRAVEL_ASSESS_FINANCE]);

        $this->postJson($this->url('/close'))
            ->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_recording_the_distance_driven_settles_it(): void
    {
        $legId = $this->leg([
            'mode' => 'self_drive', 'estimated_km' => 240,
            'estimated_cost' => 14400, 'rate_per_km_applied' => 60,
        ]);

        // Gated on seeing the request, not editing it: the trip has happened
        // and the request is no longer editable.
        $this->actingAs_([Permission::TRAVEL_VIEW_OWN], $this->traveller);
        $this->putJson($this->url("/legs/{$legId}"), ['actual_km' => 300])->assertOk();

        $this->actingAs_([Permission::TRAVEL_VIEW, Permission::TRAVEL_ASSESS_FINANCE]);
        $this->postJson($this->url('/close'))->assertOk();

        $this->assertSame('closed', $this->request->fresh()->status);
    }

    public function test_the_variance_is_reported_against_the_rate_that_was_approved(): void
    {
        // Sixty extra kilometres at the snapshotted rate, not at whatever the
        // rate happens to be when somebody looks.
        $legId = $this->leg([
            'mode' => 'self_drive', 'estimated_km' => 240,
            'estimated_cost' => 14400, 'rate_per_km_applied' => 60,
        ]);

        $this->actingAs_([Permission::TRAVEL_VIEW_OWN], $this->traveller);
        $this->putJson($this->url("/legs/{$legId}"), ['actual_km' => 300])->assertOk();

        TransportMileageRate::query()->update(['rate_per_km' => 999]);

        $res = $this->getJson($this->url(''))->assertOk();
        $leg = collect($res->json('data.transport_legs'))->firstWhere('id', $legId);

        $this->assertEquals(18000, (float) $leg['actual_value']);
        $this->assertEquals(3600, (float) $leg['variance']);
    }

    public function test_an_unsettled_journey_reports_no_variance_rather_than_zero(): void
    {
        // Zero would read as "drove exactly what was estimated", which is a
        // different statement from "we do not know yet".
        $legId = $this->leg([
            'mode' => 'self_drive', 'estimated_km' => 240, 'estimated_cost' => 14400,
        ]);

        $this->actingAs_([Permission::TRAVEL_VIEW, Permission::TRAVEL_ASSESS_FINANCE]);

        $res = $this->getJson($this->url(''))->assertOk();
        $leg = collect($res->json('data.transport_legs'))->firstWhere('id', $legId);

        $this->assertNull($leg['variance']);
    }

    public function test_a_taxi_settles_on_what_was_paid_rather_than_on_distance(): void
    {
        // There is no distance to record for a fare somebody was charged.
        $legId = $this->leg(['mode' => 'taxi', 'estimated_cost' => 2000]);

        $this->actingAs_([Permission::TRAVEL_VIEW_OWN], $this->traveller);
        $this->putJson($this->url("/legs/{$legId}"), ['actual_cost' => 2650])->assertOk();

        $this->actingAs_([Permission::TRAVEL_VIEW, Permission::TRAVEL_ASSESS_FINANCE]);
        $this->postJson($this->url('/close'))->assertOk();

        $this->assertSame('closed', $this->request->fresh()->status);
    }
}
