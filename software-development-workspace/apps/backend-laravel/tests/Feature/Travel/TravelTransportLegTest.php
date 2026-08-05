<?php

namespace Tests\Feature\Travel;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Travel\TransportMileageRate;
use App\Models\Travel\TransportVehicleType;
use App\Models\Travel\TravelApprovalStage;
use App\Models\Travel\TravelExpenseCategory;
use App\Models\Travel\TravelRequest;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Claiming transport on a request.
 *
 * The journey is costed from distance, fuel and mode — not from a grade
 * allowance — and what kind of journey it is decides who approves it. Both of
 * those are derived rather than declared, because a routing key the requester
 * types is one they can set to avoid an approver.
 */
class TravelTransportLegTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);

        $this->department = Department::create([
            'organization_id' => $this->org->id, 'name' => 'Engineering',
            'lead_employee_id' => $this->employee()->id,
        ]);

        TransportMileageRate::create([
            'organization_id' => $this->org->id, 'rate_per_km' => 60, 'effective_from' => '2026-01-01',
        ]);

        TravelApprovalStage::insert([
            ['organization_id' => $this->org->id, 'stage' => 'department_head', 'travel_type' => null,
             'sequence' => 1, 'required_above_amount' => null, 'is_active' => true, 'currency' => 'KES',
             'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $this->org->id, 'stage' => 'finance', 'travel_type' => 'delivery',
             'sequence' => 1, 'required_above_amount' => null, 'is_active' => true, 'currency' => 'KES',
             'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function employee(): Employee
    {
        return Employee::create([
            'employee_number'         => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id,
            'first_name' => 'Test', 'last_name' => 'Person',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
        ]);
    }

    private function actingAsWith(array $permissions): Employee
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Test', 'scope' => 'organization', 'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();

        $employee = $this->employee();
        $employee->update(['user_id' => $user->id, 'department_id' => $this->department->id]);
        Sanctum::actingAs($user);

        return $employee->fresh();
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/travel-requests{$path}?org={$this->org->slug}";
    }

    private function raise(): int
    {
        $traveller = $this->actingAsWith([
            Permission::TRAVEL_SUBMIT, Permission::TRAVEL_VIEW_OWN, Permission::TRAVEL_VIEW,
        ]);

        return $this->postJson($this->url(), [
            'employee_id'    => $traveller->id,
            'departure_date' => '2026-09-01',
            'return_date'    => '2026-09-02',
        ])->assertCreated()->json('data.id');
    }

    // ── Costing ───────────────────────────────────────────────────────────

    public function test_a_self_drive_leg_is_costed_on_submission_to_the_request(): void
    {
        $id  = $this->raise();
        $res = $this->postJson($this->url("/{$id}/legs"), [
            'mode' => 'self_drive', 'estimated_km' => 240,
        ])->assertOk();

        $this->assertEquals(14400, (float) $res->json('data.transport_legs.0.estimated_cost'));
        $this->assertEquals(14400, (float) $res->json('data.total_claimed'),
            'The journey counts towards the request total, or thresholds would ignore it.');
    }

    public function test_the_mileage_rate_is_snapshotted_onto_the_leg(): void
    {
        // Reconciling months later against whatever the rate is by then would
        // re-judge a decision already made.
        $id = $this->raise();
        $this->postJson($this->url("/{$id}/legs"), ['mode' => 'self_drive', 'estimated_km' => 100])->assertOk();

        TransportMileageRate::query()->update(['rate_per_km' => 999]);

        $res = $this->getJson($this->url("/{$id}"))->assertOk();
        $this->assertEquals(60, (float) $res->json('data.transport_legs.0.rate_per_km_applied'));
    }

    public function test_a_hired_vehicle_is_costed_on_the_fuel_it_burns(): void
    {
        $type = TransportVehicleType::create([
            'organization_id' => $this->org->id, 'name' => '4x4', 'km_per_litre' => 8,
        ]);

        $id  = $this->raise();
        $res = $this->postJson($this->url("/{$id}/legs"), [
            'mode' => 'hired_vehicle', 'estimated_km' => 400,
            'transport_vehicle_type_id' => $type->id, 'fuel_price_per_litre' => 195,
        ])->assertOk();

        $this->assertEquals(9750, (float) $res->json('data.transport_legs.0.estimated_cost'));
    }

    public function test_expenses_and_journeys_both_count_towards_the_total(): void
    {
        // Costing expenses alone would silently drop the journeys, and the
        // threshold that picks approvers would then use the wrong figure.
        $category = TravelExpenseCategory::create([
            'organization_id' => $this->org->id, 'name' => 'Per diem',
        ]);

        $id = $this->raise();
        $this->postJson($this->url("/{$id}/legs"), ['mode' => 'self_drive', 'estimated_km' => 100])->assertOk();
        $res = $this->postJson($this->url("/{$id}/items"), [
            'travel_expense_category_id' => $category->id, 'amount_claimed' => 4500,
        ])->assertOk();

        $this->assertEquals(10500, (float) $res->json('data.total_claimed'), '6,000 driven plus 4,500 per diem.');
    }

    public function test_a_vehicle_type_from_another_organisation_is_refused(): void
    {
        $beta = Organization::create(['name' => 'Beta', 'slug' => 'beta']);
        $foreign = TransportVehicleType::create([
            'organization_id' => $beta->id, 'name' => 'Van', 'km_per_litre' => 10,
        ]);

        $id = $this->raise();

        $this->postJson($this->url("/{$id}/legs"), [
            'mode' => 'hired_vehicle', 'estimated_km' => 100,
            'transport_vehicle_type_id' => $foreign->id, 'fuel_price_per_litre' => 195,
        ])->assertStatus(422)->assertJsonValidationErrors('transport_vehicle_type_id');
    }

    // ── Routing ───────────────────────────────────────────────────────────

    public function test_a_delivery_leg_makes_the_request_a_delivery(): void
    {
        // Derived, not declared. The type decides which chain applies, so
        // leaving it to the requester makes it something they can set to avoid
        // an approver — while an item description and weight is evidence of
        // what the journey actually is.
        $id  = $this->raise();
        $res = $this->postJson($this->url("/{$id}/legs"), [
            'mode' => 'hired_vehicle', 'estimated_km' => 60, 'is_delivery' => true,
            'item_description' => 'Rack servers', 'item_weight_kg' => 180,
        ])->assertOk();

        $this->assertSame('delivery', $res->json('data.travel_type'));
    }

    public function test_a_delivery_routes_to_finance_rather_than_the_line_manager(): void
    {
        $id = $this->raise();
        $this->postJson($this->url("/{$id}/legs"), [
            'mode' => 'self_drive', 'estimated_km' => 60, 'is_delivery' => true,
            'item_description' => 'Switch', 'item_weight_kg' => 12,
        ])->assertOk();

        $stages = collect($this->postJson($this->url("/{$id}/submit"))->assertOk()->json('data.approvals'))
            ->pluck('stage')->all();

        $this->assertSame(['finance'], $stages);
    }

    public function test_an_ordinary_journey_still_routes_to_the_line_manager(): void
    {
        $id = $this->raise();
        $this->postJson($this->url("/{$id}/legs"), ['mode' => 'self_drive', 'estimated_km' => 60])->assertOk();

        $stages = collect($this->postJson($this->url("/{$id}/submit"))->assertOk()->json('data.approvals'))
            ->pluck('stage')->all();

        $this->assertSame(['department_head'], $stages);
    }

    // ── After approval ────────────────────────────────────────────────────

    public function test_distance_driven_can_be_recorded_after_approval(): void
    {
        // The trip has happened by then; refusing the actual would leave
        // nothing to reconcile against.
        $id = $this->raise();
        $legId = $this->postJson($this->url("/{$id}/legs"), [
            'mode' => 'self_drive', 'estimated_km' => 240,
        ])->json('data.transport_legs.0.id');

        TravelRequest::find($id)->update(['status' => 'approved']);

        $this->putJson($this->url("/{$id}/legs/{$legId}"), ['actual_km' => 300])->assertOk();
    }

    public function test_an_estimate_cannot_be_changed_after_approval(): void
    {
        // That would alter what was agreed to.
        $id = $this->raise();
        $legId = $this->postJson($this->url("/{$id}/legs"), [
            'mode' => 'self_drive', 'estimated_km' => 240,
        ])->json('data.transport_legs.0.id');

        TravelRequest::find($id)->update(['status' => 'approved']);

        $this->putJson($this->url("/{$id}/legs/{$legId}"), ['estimated_km' => 900])->assertForbidden();
    }
}
