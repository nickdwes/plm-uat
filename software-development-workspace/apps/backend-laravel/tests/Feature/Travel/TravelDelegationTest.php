<?php

namespace Tests\Feature\Travel;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Travel\TravelAllowanceRate;
use App\Models\Travel\TravelApprovalStage;
use App\Models\Travel\TravelDepartmentApprover;
use App\Models\Travel\TravelExpenseCategory;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Named approvers acting on a department head's behalf.
 *
 * One person named as the head means one person on leave stops every request
 * behind them. In practice the work still happens — somebody phones the head
 * and approves it under their own login — and the record then says the head
 * decided something they never saw.
 *
 * The delegate decides in their own name, which is the property these tests
 * hold: it must remain answerable who actually signed.
 */
class TravelDelegationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Department $engineering;
    private Employee $head;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);

        $this->engineering = Department::create([
            'organization_id' => $this->org->id, 'name' => 'Engineering',
        ]);

        $meals = TravelExpenseCategory::create([
            'organization_id' => $this->org->id, 'name' => 'Meals',
        ]);

        TravelAllowanceRate::create([
            'organization_id'            => $this->org->id,
            'travel_expense_category_id' => $meals->id,
            'amount'                     => 1500,
            'effective_from'             => '2026-01-01',
        ]);

        TravelApprovalStage::create([
            'organization_id' => $this->org->id, 'stage' => 'department_head', 'sequence' => 1,
        ]);

        $this->head = $this->employee();
        $this->engineering->update(['lead_employee_id' => $this->head->id]);
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

        $employee = $employee ?? $this->employee($this->engineering->id);
        $employee->update(['user_id' => $user->id]);

        Sanctum::actingAs($user);

        return $employee->fresh();
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/travel-requests{$path}?org={$this->org->slug}";
    }

    /** A submitted request from an Engineering employee, awaiting the head. */
    private function submittedRequest(): int
    {
        $traveller = $this->actingAsWith([Permission::TRAVEL_SUBMIT, Permission::TRAVEL_VIEW_OWN]);

        $id = $this->postJson($this->url(), [
            'employee_id'    => $traveller->id,
            'departure_date' => '2026-09-01',
            'return_date'    => '2026-09-03',
        ])->assertCreated()->json('data.id');

        // The traveller states the cost; a request with no lines cannot be
        // submitted, because an empty request tells an approver nothing.
        $this->postJson($this->url("/{$id}/items"), [
            'travel_expense_category_id' => TravelExpenseCategory::first()->id,
            'amount_claimed'             => 4500,
        ])->assertOk();

        $this->postJson($this->url("/{$id}/submit"))->assertOk();

        return $id;
    }

    private function delegate(Employee $employee, array $attrs = []): TravelDepartmentApprover
    {
        return TravelDepartmentApprover::create($attrs + [
            'organization_id' => $this->org->id,
            'department_id'   => $this->engineering->id,
            'employee_id'     => $employee->id,
        ]);
    }

    // ── Acting on behalf ──────────────────────────────────────────────────

    public function test_a_named_approver_can_decide_in_the_heads_absence(): void
    {
        $id = $this->submittedRequest();

        $cover = $this->employee();
        $this->delegate($cover);

        $this->actingAsWith([Permission::TRAVEL_APPROVE, Permission::TRAVEL_VIEW], $cover);

        $this->postJson($this->url("/{$id}/decision"), ['decision' => 'approve'])->assertOk();
    }

    public function test_the_record_shows_who_actually_signed(): void
    {
        // The whole point of routing through a delegate rather than sharing a
        // login: "approved by X on behalf of Y" must stay answerable.
        $id = $this->submittedRequest();

        $cover = $this->employee();
        $this->delegate($cover);

        $this->actingAsWith([Permission::TRAVEL_APPROVE, Permission::TRAVEL_VIEW], $cover);
        $this->postJson($this->url("/{$id}/decision"), ['decision' => 'approve'])->assertOk();

        $row = DB::table('travel_approvals')->where('travel_request_id', $id)->first();

        $this->assertSame($this->head->id, $row->expected_approver_id, 'Routed to the head.');
        $this->assertSame($cover->id, $row->decided_by_employee_id, 'Decided by the delegate.');
    }

    public function test_being_listed_without_authority_does_not_permit_deciding(): void
    {
        // Adding someone to the list is not the same act as giving them the
        // authority to sign.
        $id = $this->submittedRequest();

        $observer = $this->employee();
        $this->delegate($observer, ['can_act_on_behalf' => false]);

        $this->actingAsWith([Permission::TRAVEL_APPROVE, Permission::TRAVEL_VIEW], $observer);

        $this->postJson($this->url("/{$id}/decision"), ['decision' => 'approve'])->assertForbidden();
    }

    public function test_a_delegate_for_another_department_cannot_decide(): void
    {
        $id = $this->submittedRequest();

        $sales = Department::create(['organization_id' => $this->org->id, 'name' => 'Sales']);
        $other = $this->employee();
        TravelDepartmentApprover::create([
            'organization_id' => $this->org->id,
            'department_id'   => $sales->id,
            'employee_id'     => $other->id,
        ]);

        $this->actingAsWith([Permission::TRAVEL_APPROVE, Permission::TRAVEL_VIEW], $other);

        $this->postJson($this->url("/{$id}/decision"), ['decision' => 'approve'])->assertForbidden();
    }

    public function test_an_expired_cover_arrangement_stops_working_on_its_own(): void
    {
        // Cover for a fortnight's leave should lapse without anyone
        // remembering to remove it.
        $id = $this->submittedRequest();

        $cover = $this->employee();
        $this->delegate($cover, [
            'effective_from' => now()->subDays(30)->toDateString(),
            'effective_to'   => now()->subDay()->toDateString(),
        ]);

        $this->actingAsWith([Permission::TRAVEL_APPROVE, Permission::TRAVEL_VIEW], $cover);

        $this->postJson($this->url("/{$id}/decision"), ['decision' => 'approve'])->assertForbidden();
    }

    public function test_a_future_cover_arrangement_is_not_yet_in_force(): void
    {
        $id = $this->submittedRequest();

        $cover = $this->employee();
        $this->delegate($cover, ['effective_from' => now()->addWeek()->toDateString()]);

        $this->actingAsWith([Permission::TRAVEL_APPROVE, Permission::TRAVEL_VIEW], $cover);

        $this->postJson($this->url("/{$id}/decision"), ['decision' => 'approve'])->assertForbidden();
    }

    public function test_a_delegate_still_needs_the_approve_permission(): void
    {
        // Delegation names who may act for a team; it does not itself confer
        // the right to approve travel at all.
        $id = $this->submittedRequest();

        $cover = $this->employee();
        $this->delegate($cover);

        $this->actingAsWith([Permission::TRAVEL_VIEW], $cover);

        $this->postJson($this->url("/{$id}/decision"), ['decision' => 'approve'])->assertForbidden();
    }

    public function test_the_head_can_still_decide_themselves(): void
    {
        $id = $this->submittedRequest();

        $this->actingAsWith([Permission::TRAVEL_APPROVE, Permission::TRAVEL_VIEW], $this->head);

        $this->postJson($this->url("/{$id}/decision"), ['decision' => 'approve'])->assertOk();
    }
}
