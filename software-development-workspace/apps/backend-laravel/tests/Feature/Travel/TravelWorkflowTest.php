<?php

namespace Tests\Feature\Travel;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Travel\TravelApprovalStage;
use App\Models\Travel\TravelDestination;
use App\Models\Travel\TravelExpenseCategory;
use App\Models\Travel\TravelAllowanceRate;
use App\Models\Travel\TravelRequest;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Raising, costing and approving a travel request.
 *
 * The properties worth fixing are the ones that fail quietly: a request costed
 * against a rate that has since changed, an approver signing out of turn, and
 * anyone who can approve a trip also being able to release the money.
 */
class TravelWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Department $department;
    private TravelExpenseCategory $meals;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);

        $this->department = Department::create([
            'organization_id' => $this->org->id, 'name' => 'Engineering',
        ]);

        $this->meals = TravelExpenseCategory::create([
            'organization_id' => $this->org->id, 'name' => 'Meals',
        ]);

        TravelApprovalStage::insert([
            ['organization_id' => $this->org->id, 'stage' => 'department_head', 'sequence' => 1, 'is_active' => true, 'currency' => 'KES', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $this->org->id, 'stage' => 'finance',         'sequence' => 3, 'is_active' => true, 'currency' => 'KES', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function employee(?int $departmentId = null): Employee
    {
        return Employee::create([
            'employee_number'         => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id,
            'first_name'              => 'Test', 'last_name' => 'Person',
            'employment_type'         => 'permanent', 'date_hired' => '2024-01-01',
            'department_id'           => $departmentId,
        ]);
    }

    private function actingAsWith(array $permissions, ?Employee $employee = null): Employee
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name'         => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Test', 'scope' => 'organization', 'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();

        $employee = $employee ?? $this->employee($this->department->id);
        $employee->update(['user_id' => $user->id]);

        Sanctum::actingAs($user);

        return $employee->fresh();
    }

    private function rate(float $amount, ?int $destinationId = null): TravelAllowanceRate
    {
        return TravelAllowanceRate::create([
            'organization_id'            => $this->org->id,
            'travel_expense_category_id' => $this->meals->id,
            'travel_destination_id'      => $destinationId,
            'amount'                     => $amount,
            'effective_from'             => '2026-01-01',
        ]);
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/travel-requests{$path}?org={$this->org->slug}";
    }

    private function payload(Employee $traveller): array
    {
        return [
            'employee_id'    => $traveller->id,
            'departure_date' => '2026-09-01',
            'return_date'    => '2026-09-03',
            'purpose'        => 'Customer visit',
        ];
    }

    // ── Raising ───────────────────────────────────────────────────────────

    public function test_a_request_is_costed_from_the_rate_card_on_creation(): void
    {
        $this->rate(1500);
        $traveller = $this->actingAsWith([Permission::TRAVEL_SUBMIT, Permission::TRAVEL_VIEW_OWN]);

        $res = $this->postJson($this->url(), $this->payload($traveller))->assertCreated();

        // Three days inclusive at 1,500 per day.
        $this->assertSame(3, $res->json('data.days'));
        $this->assertEquals(4500, (float) $res->json('data.total_claimed'));
    }

    public function test_an_employee_of_another_organisation_cannot_be_named(): void
    {
        $this->rate(1500);
        $beta = Organization::create(['name' => 'Beta', 'slug' => 'beta']);
        $foreign = Employee::create([
            'employee_number'         => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $beta->id,
            'first_name'              => 'Other', 'last_name' => 'Company',
            'employment_type'         => 'permanent', 'date_hired' => '2024-01-01',
        ]);

        $this->actingAsWith([Permission::TRAVEL_SUBMIT]);

        $this->postJson($this->url(), $this->payload($foreign))
            ->assertStatus(422)->assertJsonValidationErrors('employee_id');
    }

    public function test_a_destination_belonging_to_another_organisation_is_rejected(): void
    {
        $beta = Organization::create(['name' => 'Beta', 'slug' => 'beta']);
        $foreign = TravelDestination::create(['organization_id' => $beta->id, 'name' => 'Elsewhere']);

        $traveller = $this->actingAsWith([Permission::TRAVEL_SUBMIT]);

        $this->postJson($this->url(), $this->payload($traveller) + ['travel_destination_id' => $foreign->id])
            ->assertStatus(422)->assertJsonValidationErrors('travel_destination_id');
    }

    public function test_a_request_with_nothing_priced_cannot_be_submitted(): void
    {
        // No rate exists, so there is nothing to approve. Submitting would put
        // an empty request in an approver's queue.
        $traveller = $this->actingAsWith([Permission::TRAVEL_SUBMIT, Permission::TRAVEL_VIEW_OWN]);
        $id = $this->postJson($this->url(), $this->payload($traveller))->json('data.id');

        $this->postJson($this->url("/{$id}/submit"))
            ->assertStatus(422)->assertJsonValidationErrors('status');
    }

    // ── The chain ─────────────────────────────────────────────────────────

    public function test_submission_creates_only_the_stages_that_apply(): void
    {
        $this->rate(1500);

        // Executive only above 200,000; this request is 4,500.
        TravelApprovalStage::create([
            'organization_id' => $this->org->id, 'stage' => 'executive', 'sequence' => 4,
            'required_above_amount' => 200000, 'currency' => 'KES',
        ]);

        $traveller = $this->actingAsWith([Permission::TRAVEL_SUBMIT, Permission::TRAVEL_VIEW_OWN]);
        $this->department->update(['lead_employee_id' => $this->employee()->id]);

        $id  = $this->postJson($this->url(), $this->payload($traveller))->json('data.id');
        $res = $this->postJson($this->url("/{$id}/submit"))->assertOk();

        $stages = collect($res->json('data.approvals'))->pluck('stage')->all();
        $this->assertSame(['department_head', 'finance'], $stages, 'The executive stage is below its threshold.');
    }

    public function test_the_rate_is_snapshotted_and_survives_a_change_to_the_card(): void
    {
        $rate = $this->rate(1500);
        $traveller = $this->actingAsWith([Permission::TRAVEL_SUBMIT, Permission::TRAVEL_VIEW_OWN]);

        $id = $this->postJson($this->url(), $this->payload($traveller))->json('data.id');

        // The organisation revises its meals rate afterwards.
        $rate->update(['amount' => 9000]);

        $res = $this->getJson($this->url("/{$id}"))->assertOk();
        $this->assertEquals(1500, (float) $res->json('data.items.0.rate_applied'));
        $this->assertEquals(4500, (float) $res->json('data.total_claimed'));
    }

    public function test_approving_a_trip_does_not_permit_releasing_the_money(): void
    {
        // travel.approve covers the structural stages only. Without this
        // split, any line manager could complete the finance stage too.
        $this->rate(1500);

        $head = $this->employee();
        $this->department->update(['lead_employee_id' => $head->id]);

        $traveller = $this->actingAsWith([Permission::TRAVEL_SUBMIT, Permission::TRAVEL_VIEW_OWN]);
        $id = $this->postJson($this->url(), $this->payload($traveller))->json('data.id');
        $this->postJson($this->url("/{$id}/submit"))->assertOk();

        // The department head approves stage 1.
        $this->actingAsWith([Permission::TRAVEL_APPROVE, Permission::TRAVEL_VIEW], $head);
        $this->postJson($this->url("/{$id}/decision"), ['decision' => 'approve'])->assertOk();

        // The same authority must not carry the finance stage.
        $this->postJson($this->url("/{$id}/decision"), ['decision' => 'approve'])->assertForbidden();
    }

    public function test_a_manager_cannot_approve_another_departments_travel(): void
    {
        // Holding travel.approve is not enough; the request must have been
        // routed to them.
        $this->rate(1500);
        $head = $this->employee();
        $this->department->update(['lead_employee_id' => $head->id]);

        $traveller = $this->actingAsWith([Permission::TRAVEL_SUBMIT, Permission::TRAVEL_VIEW_OWN]);
        $id = $this->postJson($this->url(), $this->payload($traveller))->json('data.id');
        $this->postJson($this->url("/{$id}/submit"))->assertOk();

        $otherManager = $this->employee();
        $this->actingAsWith([Permission::TRAVEL_APPROVE, Permission::TRAVEL_VIEW], $otherManager);

        $this->postJson($this->url("/{$id}/decision"), ['decision' => 'approve'])->assertForbidden();
    }

    public function test_a_rejection_stops_the_chain(): void
    {
        $this->rate(1500);
        $head = $this->employee();
        $this->department->update(['lead_employee_id' => $head->id]);

        $traveller = $this->actingAsWith([Permission::TRAVEL_SUBMIT, Permission::TRAVEL_VIEW_OWN]);
        $id = $this->postJson($this->url(), $this->payload($traveller))->json('data.id');
        $this->postJson($this->url("/{$id}/submit"))->assertOk();

        $this->actingAsWith([Permission::TRAVEL_APPROVE, Permission::TRAVEL_VIEW], $head);
        $res = $this->postJson($this->url("/{$id}/decision"), ['decision' => 'reject', 'comment' => 'Not now'])
            ->assertOk();

        $this->assertSame('rejected', $res->json('data.status'));
        // Remaining stages are closed rather than left pending, so the request
        // does not sit in the finance queue after being refused.
        $this->assertSame(
            0,
            DB::table('travel_approvals')->where('travel_request_id', $id)->where('status', 'pending')->count()
        );
    }

    public function test_a_completed_chain_marks_the_request_approved(): void
    {
        $this->rate(1500);
        $head = $this->employee();
        $this->department->update(['lead_employee_id' => $head->id]);

        $traveller = $this->actingAsWith([Permission::TRAVEL_SUBMIT, Permission::TRAVEL_VIEW_OWN]);
        $id = $this->postJson($this->url(), $this->payload($traveller))->json('data.id');
        $this->postJson($this->url("/{$id}/submit"))->assertOk();

        $this->actingAsWith([Permission::TRAVEL_APPROVE, Permission::TRAVEL_VIEW], $head);
        $this->postJson($this->url("/{$id}/decision"), ['decision' => 'approve'])->assertOk();

        $this->actingAsWith([Permission::TRAVEL_ASSESS_FINANCE, Permission::TRAVEL_VIEW]);
        $res = $this->postJson($this->url("/{$id}/decision"), ['decision' => 'approve'])->assertOk();

        $this->assertSame('approved', $res->json('data.status'));
        $this->assertEquals(4500, (float) TravelRequest::find($id)->total_approved);
    }

    public function test_a_submitted_request_can_no_longer_be_edited(): void
    {
        $this->rate(1500);
        $this->department->update(['lead_employee_id' => $this->employee()->id]);

        $traveller = $this->actingAsWith([Permission::TRAVEL_SUBMIT, Permission::TRAVEL_VIEW_OWN]);
        $id = $this->postJson($this->url(), $this->payload($traveller))->json('data.id');
        $this->postJson($this->url("/{$id}/submit"))->assertOk();

        $this->putJson($this->url("/{$id}"), $this->payload($traveller) + ['purpose' => 'Changed'])
            ->assertForbidden();
    }
}
