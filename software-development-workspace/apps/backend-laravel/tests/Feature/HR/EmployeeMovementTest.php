<?php

namespace Tests\Feature\HR;

use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\EmployeeMovement;
use App\Models\EmployeeOrganizationMap;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Moving somebody from one company in the group to another.
 *
 * One mechanism, two outcomes: a secondment and a transfer both open a
 * placement in the destination and differ only in whether the home membership
 * ends. Most of what follows is about the things a transfer must not do
 * quietly — leave a half-recovered loan behind, carry another company's
 * department across, or hand somebody a fresh year of annual leave for moving
 * desk.
 */
class EmployeeMovementTest extends TestCase
{
    use RefreshDatabase;

    private Organization $home;
    private Organization $host;
    private Employee $employee;
    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->home = Organization::create(['name' => 'Konvergenz', 'slug' => 'konvergenz']);
        $this->host = Organization::create(['name' => 'Tiberbu Healthnet', 'slug' => 'tiberbu']);

        $this->employee = $this->person('Wanjiru', 'Kamau', $this->home);
        $this->hr       = $this->signIn([$this->home, $this->host]);
    }

    private function person(string $first, string $last, Organization $org, array $attributes = []): Employee
    {
        return Employee::create(array_merge([
            'employee_number' => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $org->id,
            'first_name' => $first, 'last_name' => $last,
            'work_email' => strtolower($first) . fake()->unique()->numberBetween(1, 9999) . '@kns.co.ke',
            'employment_type' => 'permanent', 'status' => 'active', 'date_hired' => '2022-01-01',
        ], $attributes));
    }

    /** @param  list<Organization>  $orgs */
    private function signIn(array $orgs, array $permissions = null): User
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'HR', 'scope' => 'organization',
            'permissions' => $permissions ?? [
                Permission::EMPLOYEES_VIEW, Permission::EMPLOYEES_UPDATE,
                Permission::EMPLOYEES_TRANSFER,
            ],
        ]);

        foreach ($orgs as $org) {
            $user->roles()->attach($role->id, ['organization_id' => $org->id]);
        }

        $user->forgetCachedRoles();
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * The organisation is sent as a header, not a query string.
     *
     * Somebody who can move people between two companies has standing in both,
     * so the middleware will not guess which one they are acting for — which is
     * correct, and means these calls must say.
     */
    private function as(Organization $org): array
    {
        return ['X-Organization' => $org->slug];
    }

    private function move(array $body = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(
            "/api/v1/employees/{$this->employee->id}/movements",
            $body + [
                'kind' => EmployeeMovement::TRANSFER,
                'organization_id' => $this->host->id,
                'effective_on' => now()->toDateString(),
            ],
            $this->as($this->home),
        );
    }

    // ── The two outcomes ──────────────────────────────────────────────────────

    /** A transfer ends the old membership and moves the payroll. */
    public function test_a_transfer_moves_which_company_employs_them(): void
    {
        $this->move(['reason' => 'Joining the healthcare practice.'])->assertCreated();

        $this->assertSame($this->host->id, $this->employee->fresh()->primary_organization_id);

        $home = EmployeeOrganizationMap::where('employee_id', $this->employee->id)
            ->where('organization_id', $this->home->id)->firstOrFail();

        $this->assertFalse($home->is_active, 'the old membership ends');
        $this->assertNotNull($home->effective_to, 'and is dated');

        $this->assertTrue(EmployeeOrganizationMap::where('employee_id', $this->employee->id)
            ->where('organization_id', $this->host->id)->value('is_active'));
    }

    /**
     * A secondment leaves the home membership alone.
     *
     * The same mechanism, and the only difference is that one company keeps
     * paying them.
     */
    public function test_a_secondment_leaves_them_where_they_are(): void
    {
        $this->move(['kind' => EmployeeMovement::SECONDMENT, 'allocation_pct' => 40])
            ->assertCreated();

        $this->assertSame($this->home->id, $this->employee->fresh()->primary_organization_id);
        $this->assertTrue($this->employee->fresh()->is_shared);
        $this->assertSame(40, EmployeeOrganizationMap::where('organization_id', $this->host->id)
            ->value('allocation_pct'));
    }

    /** Nobody is moved to where they already are. */
    public function test_moving_somebody_to_their_own_company_is_refused(): void
    {
        $this->move(['organization_id' => $this->home->id])
            ->assertStatus(409)
            ->assertJsonPath('message', 'That is already their organisation.');
    }

    // ── What the old membership leaves behind ─────────────────────────────────

    /**
     * The record of where they used to work is created on the way out.
     *
     * Nobody has a placement row for their own primary organisation — the map
     * was only ever written for secondments — so without this a transfer erases
     * every trace that they worked there.
     */
    public function test_the_old_membership_is_written_down_even_though_it_never_existed(): void
    {
        $this->assertSame(0, EmployeeOrganizationMap::count(), 'nothing exists beforehand');

        $this->move()->assertCreated();

        $home = EmployeeOrganizationMap::where('organization_id', $this->home->id)->firstOrFail();

        $this->assertSame('2022-01-01', $home->effective_from->toDateString(),
            'dated from when they were hired, not from the move');
        $this->assertFalse($home->is_active);
    }

    // ── Per-organisation structure ────────────────────────────────────────────

    /**
     * Departments, titles and grades belong to one company each.
     *
     * Carrying the ids across would file somebody in Tiberbu under a Konvergenz
     * department. No foreign key would object and the screens would quietly
     * show another company's structure.
     */
    public function test_structure_is_matched_in_the_destination_not_carried_across(): void
    {
        $homeDept = Department::create([
            'organization_id' => $this->home->id, 'name' => 'Cloud Platforms', 'code' => 'CLOUD',
        ]);
        $hostDept = Department::create([
            'organization_id' => $this->host->id, 'name' => 'Cloud Platforms', 'code' => 'CLOUD',
        ]);

        $this->employee->forceFill(['department_id' => $homeDept->id])->save();

        $this->move()->assertCreated();

        $this->assertSame($hostDept->id, $this->employee->fresh()->department_id);
    }

    /** With no counterpart it is left empty and said, never guessed. */
    public function test_structure_with_no_counterpart_is_left_unset_and_named(): void
    {
        $homeDept = Department::create([
            'organization_id' => $this->home->id, 'name' => 'NSOC', 'code' => 'NSOC',
        ]);
        $this->employee->forceFill(['department_id' => $homeDept->id])->save();

        $this->move()->assertCreated();

        $this->assertNull($this->employee->fresh()->department_id);

        $carried = EmployeeMovement::first()->carried;

        $this->assertStringContainsString('department has no counterpart',
            implode(' ', $carried['notes']));
    }

    /**
     * The reporting line does not survive.
     *
     * A stale one is not merely untidy: manager-based authorisation walks that
     * column, so it would leave somebody at the old company with continuing
     * authority over them.
     */
    public function test_the_reporting_line_is_cleared_and_the_gap_is_named(): void
    {
        $manager = $this->person('Ali', 'Hassan', $this->home);
        $this->employee->forceFill(['reports_to' => $manager->id])->save();

        $this->move()->assertCreated();

        $this->assertNull($this->employee->fresh()->reports_to);
        $this->assertStringContainsString('reporting line was cleared',
            implode(' ', EmployeeMovement::first()->carried['notes']));
    }

    // ── Access ────────────────────────────────────────────────────────────────

    /**
     * Access to the old company is withdrawn, not erased.
     *
     * The revocation columns exist so "who could see Konvergenz in March" has
     * an answer. Deleting the row, or re-pointing it at the new organisation,
     * answers that question wrongly and leaves nothing to show it changed.
     */
    public function test_old_access_is_revoked_rather_than_deleted(): void
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Engineer', 'scope' => 'organization', 'permissions' => ['employees.view_own'],
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->home->id]);
        $this->employee->forceFill(['user_id' => $user->id])->save();

        $this->move()->assertCreated();

        $grant = DB::table('user_roles')->where('user_id', $user->id)
            ->where('organization_id', $this->home->id)->first();

        $this->assertNotNull($grant, 'the grant is kept');
        $this->assertNotNull($grant->revoked_at, 'and withdrawn');
        $this->assertSame($this->home->id, $grant->organization_id,
            'still recorded against the organisation it was for');
    }

    /** And no access is granted in the new one — said out loud, not implied. */
    public function test_no_access_is_granted_in_the_destination(): void
    {
        $user = User::factory()->create();
        $this->employee->forceFill(['user_id' => $user->id])->save();

        $this->move()->assertCreated();

        $this->assertSame(0, DB::table('user_roles')
            ->where('user_id', $user->id)->where('organization_id', $this->host->id)->count());

        $this->assertStringContainsString('cannot see their new organisation',
            EmployeeMovement::first()->carried['access_note']);
    }

    // ── Leave ─────────────────────────────────────────────────────────────────

    /**
     * This year's leave follows them, matched on the type's code.
     *
     * A fresh entitlement for moving desk would teach everybody to time a
     * transfer for January.
     */
    public function test_leave_already_taken_carries_across(): void
    {
        $homeType = $this->leaveType($this->home, 'Annual Leave', 'AL', 21);
        $hostType = $this->leaveType($this->host, 'Annual Leave', 'AL', 25);

        LeaveBalance::create([
            'employee_id' => $this->employee->id, 'leave_type_id' => $homeType->id,
            'year' => (int) now()->format('Y'),
            'entitled_days' => 21, 'used_days' => 12, 'pending_days' => 0, 'carried_days' => 0,
        ]);

        $this->move()->assertCreated();

        $carried = LeaveBalance::where('employee_id', $this->employee->id)
            ->where('leave_type_id', $hostType->id)->firstOrFail();

        $this->assertEquals(12, $carried->used_days, 'what they have taken follows them');
        $this->assertEquals(25, $carried->entitled_days, "the destination's own entitlement applies");
    }

    /** A kind of leave the destination does not offer is left behind and named. */
    public function test_a_leave_type_with_no_counterpart_is_reported(): void
    {
        $studyLeave = $this->leaveType($this->home, 'Study Leave', 'STL', 5);

        LeaveBalance::create([
            'employee_id' => $this->employee->id, 'leave_type_id' => $studyLeave->id,
            'year' => (int) now()->format('Y'),
            'entitled_days' => 5, 'used_days' => 2, 'pending_days' => 0, 'carried_days' => 0,
        ]);

        $this->move()->assertCreated();

        $this->assertContains('Study Leave', EmployeeMovement::first()->carried['leave']['left_behind']);
    }

    private function leaveType(Organization $org, string $name, string $code, int $days): LeaveType
    {
        return LeaveType::create([
            'organization_id' => $org->id, 'name' => $name, 'code' => $code,
            'days_per_year' => $days, 'is_paid' => true, 'carry_forward' => false,
            'requires_approval' => true, 'is_active' => true, 'counting_method' => 'business_days',
        ]);
    }

    // ── The handover check ────────────────────────────────────────────────────

    /**
     * A half-recovered loan stops the move.
     *
     * Outstanding is worked out from past payslips — there is no stored
     * balance — so a schedule re-created in the new company restarts at zero
     * recovered and keeps deducting past the principal. Somebody has to decide
     * the reduced figure, and it is not this code.
     */
    public function test_an_unfinished_deduction_blocks_the_move(): void
    {
        $this->recurringDeduction();

        $this->move()
            ->assertStatus(409)
            ->assertJsonPath('preflight.clear', false);

        $this->assertSame($this->home->id, $this->employee->fresh()->primary_organization_id,
            'nothing moved');
    }

    /** It can be overridden, but only by saying so. */
    public function test_the_block_can_be_overridden_deliberately(): void
    {
        $this->recurringDeduction();

        $this->move(['force' => true])->assertCreated();

        $this->assertSame($this->host->id, $this->employee->fresh()->primary_organization_id);
    }

    /** And what was outstanding is kept with the movement, not thrown away. */
    public function test_what_was_outstanding_is_recorded_with_the_move(): void
    {
        $this->recurringDeduction();

        $this->move(['force' => true])->assertCreated();

        $blocking = collect(EmployeeMovement::first()->preflight['blocking'])->pluck('key');

        $this->assertContains('recurring_deductions', $blocking->all());
    }

    /** People who report to them is the other thing nobody should discover later. */
    public function test_direct_reports_block_the_move(): void
    {
        $this->person('Junior', 'Person', $this->home, ['reports_to' => $this->employee->id]);

        $response = $this->move()->assertStatus(409);

        $this->assertContains('direct_reports',
            collect($response->json('preflight.blocking'))->pluck('key')->all());
    }

    public function test_the_preflight_can_be_read_before_committing_to_anything(): void
    {
        $this->recurringDeduction();

        $this->getJson("/api/v1/employees/{$this->employee->id}/movements/preflight",
            $this->as($this->home))
            ->assertOk()
            ->assertJsonPath('data.clear', false)
            ->assertJsonPath('employee.organization.name', 'Konvergenz');

        $this->assertSame($this->home->id, $this->employee->fresh()->primary_organization_id);
    }

    private function recurringDeduction(): void
    {
        $code = DB::table('payroll_deduction_codes')->insertGetId([
            'organization_id' => $this->home->id, 'name' => 'SACCO', 'code' => 'SACCO',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('employee_recurring_deductions')->insert([
            'organization_id' => $this->home->id, 'employee_id' => $this->employee->id,
            'payroll_deduction_code_id' => $code, 'label' => 'SACCO loan',
            'instalment' => 5000, 'principal' => 100000, 'starts_on' => '2025-01-01',
            'status' => 'active', 'priority' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── Who may do it ─────────────────────────────────────────────────────────

    /** Updating an employee is not moving them to another company. */
    public function test_editing_an_employee_does_not_let_you_move_them(): void
    {
        $this->signIn([$this->home, $this->host],
            [Permission::EMPLOYEES_VIEW, Permission::EMPLOYEES_UPDATE]);

        $this->move()->assertForbidden();
    }

    /**
     * The permission is needed at both ends.
     *
     * Holding it only where the request was made would let anybody who can move
     * their own staff post them into a company that never agreed to employ them.
     */
    public function test_the_permission_is_needed_in_the_destination_too(): void
    {
        $this->signIn([$this->home]);   // no standing in Tiberbu at all

        $this->move()->assertForbidden();
    }

    // ── The record ────────────────────────────────────────────────────────────

    public function test_the_move_is_written_down_with_who_did_it_and_why(): void
    {
        $this->move(['reason' => 'Joining the healthcare practice.'])->assertCreated();

        $movement = EmployeeMovement::firstOrFail();

        $this->assertSame($this->home->id, $movement->from_organization_id);
        $this->assertSame($this->host->id, $movement->to_organization_id);
        $this->assertSame(EmployeeMovement::TRANSFER, $movement->kind);
        $this->assertSame('Joining the healthcare practice.', $movement->reason);
        $this->assertSame($this->hr->id, $movement->moved_by);
    }

    public function test_the_history_can_be_read_back(): void
    {
        $this->move()->assertCreated();

        $this->getJson("/api/v1/employees/{$this->employee->id}/movements", $this->as($this->host))
            ->assertOk()
            ->assertJsonPath('data.0.kind', EmployeeMovement::TRANSFER)
            ->assertJsonPath('data.0.to.name', 'Tiberbu Healthnet');
    }

    /** The reply says what happened in words somebody can read back. */
    public function test_the_reply_says_what_happened(): void
    {
        $this->move()->assertCreated()
            ->assertJsonPath('message', 'Wanjiru Kamau now works for Tiberbu Healthnet.');
    }
}
