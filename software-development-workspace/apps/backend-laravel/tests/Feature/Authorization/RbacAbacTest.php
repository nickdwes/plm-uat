<?php

namespace Tests\Feature\Authorization;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permission;
use App\Support\Authorization\ResourceAttributes;
use App\Support\Authorization\Scope;
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

    public function test_repeated_permission_checks_do_not_requery(): void
    {
        $org  = $this->makeOrg('alpha');
        $user = User::factory()->create();
        $this->assign($user, $this->makeRole(Role::ORG_HR, 'organization', ['employees.*']), $org->id);

        // Warm the memoized assignment and deny lookups.
        $user->hasPermission(Permission::EMPLOYEES_VIEW, $org->id);

        \DB::enableQueryLog();
        \DB::flushQueryLog();

        // Each of these previously issued its own query, and the nested role
        // helpers issued three apiece.
        $user->isManagerIn($org->id);
        $user->isOrgHR($org->id);
        $user->isGroupAdmin();
        $user->hasPermission(Permission::EMPLOYEES_UPDATE, $org->id);
        $user->hasPermission(Permission::EMPLOYEES_VIEW, $org->id);
        $user->scopeForPermission(Permission::EMPLOYEES_VIEW, $org->id);

        $this->assertCount(
            0,
            \DB::getQueryLog(),
            'Authorization must not query once assignments and denials are resolved.'
        );
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

    // ── Scope ─────────────────────────────────────────────────────────────────

    public function test_scope_ordering_is_containment_not_equality(): void
    {
        // A wider grant satisfies a narrower requirement, never the reverse.
        $this->assertTrue(Scope::covers(Scope::ORGANIZATION, Scope::SELF));
        $this->assertTrue(Scope::covers(Scope::TEAM, Scope::SELF));
        $this->assertFalse(Scope::covers(Scope::SELF, Scope::ORGANIZATION));
        $this->assertFalse(Scope::covers(Scope::TEAM, Scope::DEPARTMENT));
    }

    public function test_an_unrecognised_scope_never_outranks_a_real_one(): void
    {
        $this->assertFalse(Scope::covers('everything', Scope::SELF));
        $this->assertSame(Scope::TEAM, Scope::widest([Scope::SELF, 'bogus', Scope::TEAM]));
    }

    public function test_scope_for_permission_returns_the_widest_grant(): void
    {
        $org  = $this->makeOrg('alpha');
        $user = User::factory()->create();

        $narrow = $this->makeRole('viewer_self', 'organization', ['leave.view']);
        $wide   = $this->makeRole('viewer_team', 'organization', ['leave.view']);

        $user->roles()->attach($narrow->id, ['organization_id' => $org->id, 'scope' => Scope::SELF]);
        $user->roles()->attach($wide->id,   ['organization_id' => $org->id, 'scope' => Scope::TEAM]);
        $user->forgetCachedRoles();

        $this->assertSame(Scope::TEAM, $user->scopeForPermission(Permission::LEAVE_VIEW, $org->id));
    }

    public function test_scope_for_permission_is_null_when_not_held(): void
    {
        $org  = $this->makeOrg('alpha');
        $user = User::factory()->create();

        $this->assertNull($user->scopeForPermission(Permission::PAYROLL_APPROVE, $org->id));
    }

    // ── Validity window and revocation ────────────────────────────────────────

    public function test_a_lapsed_assignment_grants_nothing(): void
    {
        $org  = $this->makeOrg('alpha');
        $user = User::factory()->create();
        $role = $this->makeRole(Role::ORG_HR, 'organization', ['payroll.*']);

        $user->roles()->attach($role->id, [
            'organization_id' => $org->id,
            'scope'           => Scope::ORGANIZATION,
            'valid_from'      => now()->subMonths(2),
            'valid_until'     => now()->subDay(),
        ]);
        $user->forgetCachedRoles();

        $this->assertFalse($user->hasPermission(Permission::PAYROLL_APPROVE, $org->id));
    }

    public function test_an_assignment_not_yet_in_force_grants_nothing(): void
    {
        $org  = $this->makeOrg('alpha');
        $user = User::factory()->create();
        $role = $this->makeRole(Role::ORG_HR, 'organization', ['payroll.*']);

        // A joiner whose access starts on their first day.
        $user->roles()->attach($role->id, [
            'organization_id' => $org->id,
            'scope'           => Scope::ORGANIZATION,
            'valid_from'      => now()->addWeek(),
        ]);
        $user->forgetCachedRoles();

        $this->assertFalse($user->hasPermission(Permission::PAYROLL_APPROVE, $org->id));
    }

    public function test_a_revoked_assignment_grants_nothing_but_is_retained(): void
    {
        $org  = $this->makeOrg('alpha');
        $user = User::factory()->create();
        $role = $this->makeRole(Role::ORG_HR, 'organization', ['payroll.*']);

        $user->roles()->attach($role->id, [
            'organization_id' => $org->id,
            'scope'           => Scope::ORGANIZATION,
            'revoked_at'      => now()->subHour(),
        ]);
        $user->forgetCachedRoles();

        $this->assertFalse($user->hasPermission(Permission::PAYROLL_APPROVE, $org->id));

        // "Who had access last March" must stay answerable.
        $this->assertDatabaseHas('user_roles', ['user_id' => $user->id, 'role_id' => $role->id]);
    }

    // ── Excluded permissions ──────────────────────────────────────────────────

    public function test_an_exclusion_carves_out_of_a_wildcard(): void
    {
        $org  = $this->makeOrg('alpha');
        $user = User::factory()->create();

        $role = Role::create([
            'name'                 => 'leave_admin_no_approve',
            'display_name'         => 'Leave admin without approval',
            'scope'                => 'organization',
            'permissions'          => ['leave.*'],
            'excluded_permissions' => ['leave.approve'],
        ]);

        $this->assign($user, $role, $org->id);

        $this->assertTrue($user->hasPermission(Permission::LEAVE_VIEW, $org->id));
        $this->assertFalse($user->hasPermission(Permission::LEAVE_APPROVE, $org->id));
    }

    // ── Deny assignments ──────────────────────────────────────────────────────

    public function test_a_deny_assignment_beats_a_grant(): void
    {
        $org  = $this->makeOrg('alpha');
        $user = User::factory()->create();
        $this->assign($user, $this->makeRole(Role::GROUP_ADMIN, 'group', ['*']), null);

        $this->assertTrue($user->hasPermission(Permission::PAYROLL_VIEW, $org->id));

        \DB::table('deny_assignments')->insert([
            'user_id'    => $user->id,
            'permission' => 'payroll.*',
            'reason'     => 'Under investigation',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user->forgetCachedRoles();

        $this->assertFalse($user->hasPermission(Permission::PAYROLL_VIEW, $org->id));
        $this->assertFalse($user->hasPermission(Permission::PAYROLL_APPROVE, $org->id));
        // Unrelated permissions are untouched.
        $this->assertTrue($user->hasPermission(Permission::EMPLOYEES_VIEW, $org->id));
    }

    public function test_an_expired_deny_no_longer_applies(): void
    {
        $org  = $this->makeOrg('alpha');
        $user = User::factory()->create();
        $this->assign($user, $this->makeRole(Role::GROUP_ADMIN, 'group', ['*']), null);

        \DB::table('deny_assignments')->insert([
            'user_id'    => $user->id,
            'permission' => 'payroll.*',
            'reason'     => 'Temporary suspension, now lapsed',
            'expires_at' => now()->subDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user->forgetCachedRoles();

        $this->assertTrue($user->hasPermission(Permission::PAYROLL_VIEW, $org->id));
    }

    public function test_a_deny_scoped_to_one_org_does_not_leak_to_another(): void
    {
        $orgA = $this->makeOrg('alpha');
        $orgB = $this->makeOrg('beta');
        $user = User::factory()->create();
        $this->assign($user, $this->makeRole(Role::GROUP_ADMIN, 'group', ['*']), null);

        \DB::table('deny_assignments')->insert([
            'user_id'         => $user->id,
            'permission'      => 'payroll.view',
            'organization_id' => $orgA->id,
            'reason'          => 'Excluded from alpha payroll',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
        $user->forgetCachedRoles();

        $this->assertFalse($user->hasPermission(Permission::PAYROLL_VIEW, $orgA->id));
        $this->assertTrue($user->hasPermission(Permission::PAYROLL_VIEW, $orgB->id));
    }

    // ── Assignable scopes ─────────────────────────────────────────────────────

    public function test_a_role_cannot_be_assigned_outside_its_assignable_scopes(): void
    {
        $role = Role::create([
            'name'              => 'dept_reviewer',
            'display_name'      => 'Departmental reviewer',
            'scope'             => 'organization',
            'permissions'       => ['employees.view'],
            'assignable_scopes' => [Scope::SELF, Scope::TEAM, Scope::DEPARTMENT],
        ]);

        $this->assertTrue($role->isAssignableAt(Scope::DEPARTMENT));
        $this->assertFalse($role->isAssignableAt(Scope::ORGANIZATION));
        $this->assertFalse($role->isAssignableAt(Scope::GROUP));
    }

    public function test_a_role_without_assignable_scopes_is_unrestricted(): void
    {
        $role = $this->makeRole('anywhere', 'organization', ['employees.view']);

        $this->assertTrue($role->isAssignableAt(Scope::SELF));
        $this->assertTrue($role->isAssignableAt(Scope::GROUP));
        $this->assertFalse($role->isAssignableAt('not_a_scope'));
    }

    // ── Catalogue-wide invariants ─────────────────────────────────────────────
    //
    // Example-based tests only prove the case you thought of. Both bugs found
    // while building this passed every example test written at the time: the
    // exclusion bug because the example happened to use a non-wildcard grant,
    // the deny-cache bug because no example added a denial mid-request.
    //
    // These iterate the whole catalogue instead, so a new permission is
    // covered the moment it is added rather than when someone remembers to
    // write a test for it. Each asserts a DENIAL, because failing open is the
    // only direction that matters.

    public function test_no_assignments_grants_nothing_in_the_entire_catalogue(): void
    {
        $org  = $this->makeOrg('alpha');
        $user = User::factory()->create();

        foreach (Permission::all() as $permission) {
            $this->assertFalse(
                $user->hasPermission($permission, $org->id),
                "A user with no assignments must not hold [{$permission}]."
            );
        }
    }

    public function test_a_disabled_account_is_denied_the_entire_catalogue(): void
    {
        $org  = $this->makeOrg('alpha');
        $user = User::factory()->create();
        $this->assign($user, $this->makeRole(Role::GROUP_ADMIN, 'group', ['*']), null);
        $user->account_status = User::STATUS_DISABLED;

        foreach (Permission::all() as $permission) {
            $this->assertFalse(
                $user->hasPermission($permission, $org->id),
                "A disabled account must not hold [{$permission}] even with a global wildcard."
            );
        }
    }

    public function test_an_exclusion_holds_for_every_permission_in_the_catalogue(): void
    {
        // The bug this guards: excluding X from a wildcard grant must deny X.
        // Checked for every permission, against a global wildcard — the widest
        // possible grant, so nothing else can be masking the result.
        $org = $this->makeOrg('alpha');

        foreach (Permission::all() as $permission) {
            $user = User::factory()->create();
            $role = Role::create([
                'name'                 => 'excluder_' . md5($permission),
                'display_name'         => 'Excluder',
                'scope'                => 'organization',
                'permissions'          => ['*'],
                'excluded_permissions' => [$permission],
            ]);
            $this->assign($user, $role, $org->id);

            $this->assertFalse(
                $user->hasPermission($permission, $org->id),
                "[{$permission}] was excluded but still granted."
            );
        }
    }

    public function test_a_deny_holds_for_every_permission_in_the_catalogue(): void
    {
        $org = $this->makeOrg('alpha');

        foreach (Permission::all() as $permission) {
            $user = User::factory()->create();
            $this->assign($user, $this->makeRole('admin_' . md5($permission), 'group', ['*']), null);

            \DB::table('deny_assignments')->insert([
                'user_id'    => $user->id,
                'permission' => $permission,
                'reason'     => 'invariant check',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $user->forgetCachedRoles();

            $this->assertFalse(
                $user->hasPermission($permission, $org->id),
                "[{$permission}] was denied but still granted."
            );
        }
    }

    public function test_every_catalogue_permission_belongs_to_exactly_one_group(): void
    {
        // A permission listed twice, or in no group, means grouped() and all()
        // disagree — and grouped() is what a role-management UI would render.
        $flat    = Permission::all();
        $unique  = array_unique($flat);

        $this->assertSame(
            count($unique),
            count($flat),
            'A permission appears in more than one group: ' .
            implode(', ', array_keys(array_filter(array_count_values($flat), fn ($n) => $n > 1)))
        );
    }

    // ── Role coverage ─────────────────────────────────────────────────────────

    public function test_the_group_admin_role_reaches_every_permission(): void
    {
        // The invariant that would have caught a real lockout: three new
        // permission families were added to the catalogue and gated in
        // controllers while no role held them. group_admin listed
        // resource-specific wildcards, so it gained nothing automatically and
        // would have been denied its own administration screens.
        //
        // Anything added to the catalogue must be reachable by the top role,
        // or shipping it is an outage.
        $org  = $this->makeOrg('alpha');
        $user = User::factory()->create();
        $this->assign($user, $this->makeRole(Role::GROUP_ADMIN, 'group', ['*']), null);

        foreach (Permission::all() as $permission) {
            $this->assertTrue(
                $user->hasPermission($permission, $org->id),
                "group_admin cannot reach [{$permission}] — gating it would lock out administrators."
            );
        }
    }

    public function test_account_status_can_be_set_by_mass_assignment(): void
    {
        // Regression: account_status was missing from User::$fillable, so
        // update(['account_status' => 'disabled']) was silently dropped and the
        // account stayed active. Every existing test set the property directly,
        // which bypasses mass-assignment protection and hid it.
        $user = User::factory()->create();

        $user->update(['account_status' => User::STATUS_DISABLED]);

        $this->assertSame(User::STATUS_DISABLED, $user->fresh()->account_status);
        $this->assertFalse($user->fresh()->isAccountActive());
    }
}
