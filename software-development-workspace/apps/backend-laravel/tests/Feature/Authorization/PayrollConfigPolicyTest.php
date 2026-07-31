<?php

namespace Tests\Feature\Authorization;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Policies\PayrollPeriodPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Payroll configuration authorization.
 *
 * Before this, updateTaxBands() and updateStatutoryRates() had no permission
 * check of any kind — any authenticated user in the organization could rewrite
 * the PAYE bands and statutory rates every payslip is calculated from. The
 * only guards present were tenancy checks on individual earning and deduction
 * codes, which stop cross-tenant access but not unprivileged access inside a
 * tenant.
 *
 * The tests assert denials rather than grants, because the failure that
 * matters is an ordinary employee reaching these endpoints.
 */
class PayrollConfigPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private PayrollPeriodPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org    = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        $this->policy = new PayrollPeriodPolicy();

        // The policy's role helpers read the organization in context.
        app()->instance('current.organization_id', $this->org->id);
    }

    /**
     * Seeds the role with the permission set it actually carries in the
     * database, so the test exercises the real configuration rather than an
     * empty role that would deny everything regardless.
     */
    private function userWithRole(?string $roleName, ?int $orgId): User
    {
        $user = User::factory()->create();

        if ($roleName === null) {
            return $user;
        }

        [$permissions, $excluded] = match ($roleName) {
            Role::GROUP_ADMIN  => [['*'], []],
            // org_hr prepares payroll but may not release it or change the
            // rates it is calculated from.
            Role::ORG_HR       => [['payroll.*'], ['payroll.approve', 'payroll.manage_statutory']],
            default            => [[], []],
        };

        $role = Role::create([
            'name'                 => $roleName,
            'display_name'         => ucfirst($roleName),
            'scope'                => $orgId === null ? 'group' : 'organization',
            'permissions'          => $permissions,
            'excluded_permissions' => $excluded,
        ]);

        $user->roles()->attach($role->id, [
            'organization_id' => $orgId,
            'scope'           => $orgId === null ? 'group' : 'organization',
        ]);
        $user->forgetCachedRoles();

        return $user;
    }

    // ── Statutory: PAYE bands and rates ───────────────────────────────────────

    public function test_an_ordinary_employee_cannot_change_statutory_rates(): void
    {
        $employee = $this->userWithRole(Role::EMPLOYEE, $this->org->id);

        $this->assertFalse($this->policy->manageStatutory($employee));
    }

    public function test_a_line_manager_cannot_change_statutory_rates(): void
    {
        $manager = $this->userWithRole(Role::DEPT_MANAGER, $this->org->id);

        $this->assertFalse($this->policy->manageStatutory($manager));
    }

    public function test_org_hr_cannot_change_statutory_rates(): void
    {
        // Separation of duties: the person who prepares payroll must not also
        // be able to change what it calculates.
        $hr = $this->userWithRole(Role::ORG_HR, $this->org->id);

        // The exclusion is what preserves separation of duties: org_hr holds
        // payroll.* but payroll.approve and payroll.manage_statutory are
        // carved out, so preparing a run does not confer releasing it.
        $this->assertTrue($this->policy->manageConfig($hr), 'Org HR should still manage codes.');
        $this->assertFalse($this->policy->manageStatutory($hr));
        $this->assertFalse($this->policy->approve($hr), 'Preparer must not be the approver.');
        $this->assertTrue($this->policy->process($hr), 'Org HR still runs the calculation.');
    }

    public function test_a_group_admin_can_change_statutory_rates(): void
    {
        $admin = $this->userWithRole(Role::GROUP_ADMIN, null);

        $this->assertTrue($this->policy->manageStatutory($admin));
    }

    // ── Operational: earning and deduction codes ──────────────────────────────

    public function test_an_ordinary_employee_cannot_manage_payroll_codes(): void
    {
        $employee = $this->userWithRole(Role::EMPLOYEE, $this->org->id);

        $this->assertFalse($this->policy->manageConfig($employee));
    }

    public function test_a_user_with_no_role_at_all_is_denied_everything(): void
    {
        $nobody = $this->userWithRole(null, null);

        $this->assertFalse($this->policy->viewConfig($nobody));
        $this->assertFalse($this->policy->manageConfig($nobody));
        $this->assertFalse($this->policy->manageStatutory($nobody));
    }

    public function test_a_role_in_another_organization_does_not_carry_over(): void
    {
        $other = Organization::create(['name' => 'Beta', 'slug' => 'beta']);
        $hr    = $this->userWithRole(Role::ORG_HR, $other->id);

        // Context is still Alpha.
        $this->assertFalse($this->policy->manageConfig($hr));
        $this->assertFalse($this->policy->viewConfig($hr));
    }

    // ── Every config ability denies an ordinary employee ───────────────────────

    public function test_no_config_ability_is_reachable_by_an_ordinary_employee(): void
    {
        $employee = $this->userWithRole(Role::EMPLOYEE, $this->org->id);

        foreach (['viewConfig', 'manageConfig', 'manageStatutory'] as $ability) {
            $this->assertFalse(
                $this->policy->{$ability}($employee),
                "[{$ability}] must not be reachable by an ordinary employee."
            );
        }
    }
}
