<?php

namespace Tests\Feature\Authorization;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permission;
use App\Support\Authorization\ResourceAttributes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the RBAC + ABAC foundation.
 *
 * Two things are being protected here. The obvious one is that permissions
 * grant what they should. The less obvious — and the reason several tests
 * below assert a *denial* — is that they grant nothing more: wildcards must
 * not cross a resource boundary, an org-scoped role must not carry into
 * another tenant, and a reporting-line check must not authorize sideways.
 */
class RbacAbacTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrg(string $slug): Organization
    {
        return Organization::create(['name' => ucfirst($slug), 'slug' => $slug]);
    }

    private function makeRole(string $name, string $scope, array $permissions): Role
    {
        return Role::create([
            'name'         => $name,
            'display_name' => ucfirst($name),
            'scope'        => $scope,
            'permissions'  => $permissions,
        ]);
    }

    private function makeEmployee(Organization $org, ?int $reportsTo = null, ?User $user = null): Employee
    {
        return Employee::create([
            'employee_number'         => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $org->id,
            'first_name'              => fake()->firstName(),
            'last_name'               => fake()->lastName(),
            'employment_type'         => 'permanent',
            'date_hired'              => '2024-01-01',
            'reports_to'              => $reportsTo,
            'user_id'                 => $user?->id,
        ]);
    }

    private function assign(User $user, Role $role, ?int $orgId): void
    {
        $user->roles()->attach($role->id, ['organization_id' => $orgId]);
        $user->forgetCachedRoles();
    }

    // ── Permission matching ───────────────────────────────────────────────────

    public function test_exact_permission_matches(): void
    {
        $this->assertTrue(Permission::satisfies('leave.approve', 'leave.approve'));
        $this->assertFalse(Permission::satisfies('leave.approve', 'leave.reject'));
    }

    public function test_resource_wildcard_grants_every_action_on_that_resource(): void
    {
        $this->assertTrue(Permission::satisfies('leave.*', 'leave.approve'));
        $this->assertTrue(Permission::satisfies('leave.*', 'leave.view_own'));
    }

    public function test_wildcard_does_not_cross_the_resource_boundary(): void
    {
        // The prefix matches as a string, but these are different resources.
        $this->assertFalse(Permission::satisfies('leave.*', 'leave_types.manage'));
        $this->assertFalse(Permission::satisfies('payroll.*', 'payroll_config.manage'));
    }

    public function test_global_wildcard_grants_everything(): void
    {
        $this->assertTrue(Permission::satisfies('*', 'audit.view'));
    }

    public function test_unknown_permission_is_rejected_outside_production(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Permission::assertKnown('leave.definitely_not_a_real_action');
    }

    public function test_every_seeded_role_permission_is_in_the_catalogue(): void
    {
        // Guards against a role granting a permission nothing ever checks.
        $seeded = [
            'employees.view', 'leave.approve', 'leave.reject', 'leave.view', 'reports.team',
            'employees.view_own', 'leave.submit', 'leave.cancel_own', 'leave.view_own',
            'payslip.view_own', 'departments.view', 'departments.manage', 'reports.org',
        ];

        foreach ($seeded as $permission) {
            $this->assertTrue(
                Permission::isKnown($permission),
                "Seeded permission [{$permission}] is missing from the catalogue."
            );
        }
    }

    // ── Organization scoping ──────────────────────────────────────────────────

    public function test_org_scoped_role_grants_only_in_that_organization(): void
    {
        $orgA = $this->makeOrg('alpha');
        $orgB = $this->makeOrg('beta');
        $user = User::factory()->create();

        $this->assign($user, $this->makeRole('org_hr', 'organization', ['employees.*']), $orgA->id);

        $this->assertTrue($user->hasPermission(Permission::EMPLOYEES_UPDATE, $orgA->id));
        $this->assertFalse($user->hasPermission(Permission::EMPLOYEES_UPDATE, $orgB->id));
    }

    public function test_group_scoped_role_grants_in_every_organization(): void
    {
        $orgA = $this->makeOrg('alpha');
        $orgB = $this->makeOrg('beta');
        $user = User::factory()->create();

        // organization_id null == group scope
        $this->assign($user, $this->makeRole('group_admin', 'group', ['*']), null);

        $this->assertTrue($user->hasPermission(Permission::PAYROLL_APPROVE, $orgA->id));
        $this->assertTrue($user->hasPermission(Permission::PAYROLL_APPROVE, $orgB->id));
    }

    public function test_permissions_from_several_roles_are_combined(): void
    {
        $org  = $this->makeOrg('alpha');
        $user = User::factory()->create();

        $this->assign($user, $this->makeRole('employee', 'organization', ['leave.submit']), $org->id);
        $this->assign($user, $this->makeRole('dept_manager', 'organization', ['leave.approve']), $org->id);

        $this->assertTrue($user->hasPermission(Permission::LEAVE_SUBMIT, $org->id));
        $this->assertTrue($user->hasPermission(Permission::LEAVE_APPROVE, $org->id));
    }

    public function test_user_without_roles_holds_nothing(): void
    {
        $org  = $this->makeOrg('alpha');
        $user = User::factory()->create();

        $this->assertFalse($user->hasPermission(Permission::LEAVE_VIEW, $org->id));
        $this->assertSame([], $user->permissionsFor($org->id));
    }

    // ── Ownership ─────────────────────────────────────────────────────────────

    public function test_user_owns_their_own_employee_record(): void
    {
        $org      = $this->makeOrg('alpha');
        $user     = User::factory()->create();
        $mine     = $this->makeEmployee($org, null, $user);
        $someone  = $this->makeEmployee($org);

        $this->assertTrue($user->owns($mine));
        $this->assertFalse($user->owns($someone));
    }

    public function test_user_without_an_employee_record_owns_nothing(): void
    {
        $org  = $this->makeOrg('alpha');
        $user = User::factory()->create();

        $this->assertFalse($user->owns($this->makeEmployee($org)));
    }

    // ── Reporting line ────────────────────────────────────────────────────────

    public function test_manager_manages_a_direct_report(): void
    {
        $org      = $this->makeOrg('alpha');
        $user     = User::factory()->create();
        $manager  = $this->makeEmployee($org, null, $user);
        $report   = $this->makeEmployee($org, $manager->id);

        $this->assertTrue($user->manages($report));
    }

    public function test_manager_manages_further_down_the_line(): void
    {
        $org       = $this->makeOrg('alpha');
        $user      = User::factory()->create();
        $manager   = $this->makeEmployee($org, null, $user);
        $lead      = $this->makeEmployee($org, $manager->id);
        $grandkid  = $this->makeEmployee($org, $lead->id);

        $this->assertTrue($user->manages($grandkid));
    }

    public function test_manager_does_not_manage_a_peer(): void
    {
        $org     = $this->makeOrg('alpha');
        $user    = User::factory()->create();
        $manager = $this->makeEmployee($org, null, $user);
        $peer    = $this->makeEmployee($org);

        $this->assertFalse($user->manages($peer));
    }

    public function test_nobody_manages_themselves(): void
    {
        $org     = $this->makeOrg('alpha');
        $user    = User::factory()->create();
        $manager = $this->makeEmployee($org, null, $user);

        $this->assertFalse($user->manages($manager));
    }

    public function test_a_cycle_in_the_reporting_line_terminates(): void
    {
        // Should not exist in the data, but authorization must not hang on it.
        $org  = $this->makeOrg('alpha');
        $user = User::factory()->create();
        $me   = $this->makeEmployee($org, null, $user);
        $a    = $this->makeEmployee($org);
        $b    = $this->makeEmployee($org, $a->id);
        $a->update(['reports_to' => $b->id]);

        $this->assertFalse($user->manages($a->fresh()));
    }

    // ── Tenancy ───────────────────────────────────────────────────────────────

    public function test_record_outside_the_current_organization_is_rejected(): void
    {
        $orgA = $this->makeOrg('alpha');
        $orgB = $this->makeOrg('beta');

        $employee = $this->makeEmployee($orgB);

        $this->assertFalse(ResourceAttributes::inCurrentOrganization($employee, $orgA->id));
        $this->assertTrue(ResourceAttributes::inCurrentOrganization($employee, $orgB->id));
    }

    public function test_null_organization_context_means_group_wide(): void
    {
        $org = $this->makeOrg('alpha');

        $this->assertTrue(ResourceAttributes::inCurrentOrganization($this->makeEmployee($org), null));
    }

    public function test_a_record_with_no_organization_column_fails_closed(): void
    {
        $orgA = $this->makeOrg('alpha');

        // A Role carries no organization_id — it cannot be placed in a tenant,
        // so it must not be treated as in scope.
        $role = $this->makeRole('floating', 'organization', []);

        $this->assertFalse(ResourceAttributes::inCurrentOrganization($role, $orgA->id));
    }

    // ── Regression: existing role helpers ─────────────────────────────────────

    public function test_role_helpers_keep_their_previous_behaviour(): void
    {
        $org  = $this->makeOrg('alpha');
        $user = User::factory()->create();

        $this->assign($user, $this->makeRole(Role::ORG_HR, 'organization', ['employees.*']), $org->id);

        $this->assertTrue($user->hasRole(Role::ORG_HR, $org->id));
        $this->assertFalse($user->hasRole(Role::ORG_HR, null));
        $this->assertTrue($user->isOrgHR($org->id));
        // org_hr implies manager, per the pre-existing hierarchy
        $this->assertTrue($user->isManagerIn($org->id));
        $this->assertFalse($user->isGroupAdmin());
    }

    public function test_group_admin_implies_org_hr_everywhere(): void
    {
        $org  = $this->makeOrg('alpha');
        $user = User::factory()->create();

        $this->assign($user, $this->makeRole(Role::GROUP_ADMIN, 'group', ['*']), null);

        $this->assertTrue($user->isGroupAdmin());
        $this->assertTrue($user->isOrgHR($org->id));
        $this->assertTrue($user->isManagerIn($org->id));
    }

    // ── Query cost ────────────────────────────────────────────────────────────

    public function test_role_assignments_are_resolved_once_per_request(): void
    {
        $org  = $this->makeOrg('alpha');
        $user = User::factory()->create();
        $this->assign($user, $this->makeRole(Role::ORG_HR, 'organization', ['employees.*']), $org->id);

        \DB::enableQueryLog();
        \DB::flushQueryLog();

        // Previously each of these issued its own query, and the nested ones
        // issued three.
        $user->isManagerIn($org->id);
        $user->isOrgHR($org->id);
        $user->isGroupAdmin();
        $user->hasPermission(Permission::EMPLOYEES_UPDATE, $org->id);
        $user->hasPermission(Permission::EMPLOYEES_VIEW, $org->id);

        $this->assertCount(1, \DB::getQueryLog(), 'Role assignments should be loaded exactly once.');
    }

    // ── Account lifecycle (JML) ───────────────────────────────────────────────

    public function test_disabled_account_is_denied_every_permission(): void
    {
        $org  = $this->makeOrg('alpha');
        $user = User::factory()->create();
        $this->assign($user, $this->makeRole(Role::ORG_HR, 'organization', ['*']), $org->id);

        $this->assertTrue($user->hasPermission(Permission::PAYROLL_APPROVE, $org->id));

        // A leaver: assignments retained for audit, account disabled.
        $user->account_status = User::STATUS_DISABLED;

        $this->assertFalse($user->hasPermission(Permission::PAYROLL_APPROVE, $org->id));
        $this->assertFalse($user->hasPermission(Permission::EMPLOYEES_VIEW, $org->id));
    }

    public function test_suspended_and_pending_accounts_are_denied(): void
    {
        $org  = $this->makeOrg('alpha');
        $user = User::factory()->create();
        $this->assign($user, $this->makeRole(Role::ORG_HR, 'organization', ['*']), $org->id);

        foreach ([User::STATUS_SUSPENDED, User::STATUS_PENDING] as $status) {
            $user->account_status = $status;
            $this->assertFalse(
                $user->hasPermission(Permission::LEAVE_APPROVE, $org->id),
                "[{$status}] must not authorise."
            );
        }
    }

    public function test_disabling_an_account_does_not_destroy_its_assignments(): void
    {
        // "Who had access last March" must remain answerable.
        $org  = $this->makeOrg('alpha');
        $user = User::factory()->create();
        $this->assign($user, $this->makeRole(Role::ORG_HR, 'organization', ['employees.*']), $org->id);

        $user->account_status = User::STATUS_DISABLED;

        $this->assertFalse($user->hasPermission(Permission::EMPLOYEES_VIEW, $org->id));
        $this->assertNotEmpty($user->permissionsFor($org->id), 'Assignments must survive disabling.');
    }
}
