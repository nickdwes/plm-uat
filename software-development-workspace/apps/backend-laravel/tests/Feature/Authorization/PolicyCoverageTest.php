<?php

namespace Tests\Feature\Authorization;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Organization;
use App\Models\Recruitment\Candidate;
use App\Models\Role;
use App\Models\Training\VendorPartner;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Every policy answers to the permission catalogue.
 *
 * Seven of them did not. They checked role names — "is a manager of anybody",
 * "is HR" — and ignored the permissions sitting in the catalogue for exactly
 * their domain. That was wrong in both directions at once: a custom role
 * granted precisely the right permission was refused, and anybody who managed
 * one person could read things across the whole organisation.
 *
 * The first test here is the one that matters, because it fails when somebody
 * adds the eighth.
 */
class PolicyCoverageTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
    }

    /**
     * A policy that never consults the catalogue cannot be granted by a role,
     * which makes the permission screen a decoration for that domain.
     *
     * Asserted structurally rather than case by case: the point is that the
     * next policy added is caught on the day it is written, not the day
     * somebody notices they cannot do their job.
     */
    public function test_no_policy_authorises_on_role_names_alone(): void
    {
        $offenders = [];

        $files = array_merge(
            glob(app_path('Policies') . '/*.php'),
            glob(app_path('Policies') . '/*/*.php'),
        );

        foreach ($files as $file) {
            $name = basename($file, '.php');

            // The base class is where the helpers live, so it is exempt.
            if ($name === 'BasePolicy') {
                continue;
            }

            $body = file_get_contents($file);

            if (! preg_match('/\$this->(can|canAny)\(/', $body)) {
                $offenders[] = $name;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'These policies authorise on role names alone and ignore the permission catalogue: '
                . implode(', ', $offenders),
        );
    }

    /**
     * The same rule, one layer further out.
     *
     * A form request refuses before the policy is ever consulted, so one that
     * asks `isOrgHR()` overrides whatever the policy decided — seven of them
     * did, including the request gating employee termination. The policy sweep
     * could not see them because it only globbed app/Policies.
     *
     * What is banned is deciding on a role name. A request that returns true,
     * or that admits any signed-in user, is not deciding at all — it is saying
     * the controller's policy check does the deciding, which is the pattern
     * most of them follow and is fine. A role-name check is different: it
     * substitutes its own answer for the policy's and wins, because it runs
     * first.
     */
    public function test_no_form_request_authorises_on_role_names(): void
    {
        $offenders = [];

        $files = array_merge(
            glob(app_path('Http/Requests') . '/*.php'),
            glob(app_path('Http/Requests') . '/*/*.php'),
        );

        foreach ($files as $file) {
            $body = file_get_contents($file);

            if (preg_match('/->(isOrgHR|isManagerIn|isOrgAdmin|isCompanyHR|isGlobalHR|hasRole)\(/', $body)) {
                $offenders[] = basename($file, '.php');
            }
        }

        sort($offenders);

        $this->assertSame(
            [],
            $offenders,
            'These form requests decide on a role name, overriding whatever the policy says: '
                . implode(', ', $offenders),
        );
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create();

        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Test', 'scope' => 'organization', 'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();

        Employee::create([
            'employee_number' => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id,
            'first_name' => 'Test', 'last_name' => 'Person',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
            'user_id' => $user->id,
        ]);

        return $user->fresh();
    }

    // ── Leave ─────────────────────────────────────────────────────────────

    /**
     * The reason on a leave request is often medical, sometimes a
     * bereavement. It read as ordinary HR admin and was gated as such.
     */
    public function test_a_leave_request_is_not_readable_by_any_passing_manager(): void
    {
        $type = LeaveType::create([
            'organization_id' => $this->org->id, 'name' => 'Annual', 'code' => 'ANN', 'days_per_year' => 21,
        ]);

        $owner = $this->userWith([Permission::LEAVE_VIEW_OWN]);
        $request = LeaveRequest::create([
            'organization_id' => $this->org->id,
            'employee_id' => $owner->employee->id,
            'leave_type_id' => $type->id,
            'from_date' => now()->toDateString(), 'to_date' => now()->addDays(2)->toDateString(),
            'days_requested' => 3, 'status' => LeaveRequest::STATUS_PENDING,
        ]);

        // Somebody who manages a different team entirely.
        $bystander = $this->userWith([Permission::EMPLOYEES_VIEW_TEAM]);
        $this->assertFalse(Gate::forUser($bystander)->allows('view', $request));

        // The person who asked.
        $this->assertTrue(Gate::forUser($owner)->allows('view', $request));

        // And whoever is allowed to read leave across the organisation.
        $hr = $this->userWith([Permission::LEAVE_VIEW]);
        $this->assertTrue(Gate::forUser($hr)->allows('view', $request));
    }

    /** A manager sees their own reports' leave, and nobody else's. */
    public function test_the_team_scope_reaches_only_that_manager_s_reports(): void
    {
        $type = LeaveType::create([
            'organization_id' => $this->org->id, 'name' => 'Annual', 'code' => 'ANN', 'days_per_year' => 21,
        ]);

        $manager = $this->userWith([Permission::LEAVE_VIEW_TEAM]);
        $report  = $this->userWith([Permission::LEAVE_VIEW_OWN]);
        $report->employee->update(['reports_to' => $manager->employee->id]);

        $theirs = LeaveRequest::create([
            'organization_id' => $this->org->id, 'employee_id' => $report->employee->id,
            'leave_type_id' => $type->id,
            'from_date' => now()->toDateString(), 'to_date' => now()->addDay()->toDateString(),
            'days_requested' => 2, 'status' => LeaveRequest::STATUS_PENDING,
        ]);

        $elsewhere = $this->userWith([Permission::LEAVE_VIEW_OWN]);
        $notTheirs = LeaveRequest::create([
            'organization_id' => $this->org->id, 'employee_id' => $elsewhere->employee->id,
            'leave_type_id' => $type->id,
            'from_date' => now()->toDateString(), 'to_date' => now()->addDay()->toDateString(),
            'days_requested' => 2, 'status' => LeaveRequest::STATUS_PENDING,
        ]);

        $this->assertTrue(Gate::forUser($manager)->allows('view', $theirs));
        $this->assertFalse(Gate::forUser($manager)->allows('view', $notTheirs));
    }

    // ── Candidates ────────────────────────────────────────────────────────

    /**
     * Candidates are people outside the company who sent us a CV on the
     * strength of an advert. They have no account and cannot see who reads it.
     */
    public function test_a_candidate_is_not_readable_by_any_passing_manager(): void
    {
        $candidate = Candidate::create([
            'organization_id' => $this->org->id,
            'first_name' => 'Amina', 'last_name' => 'Otieno', 'email' => 'amina@example.com',
        ]);

        $bystander = $this->userWith([Permission::EMPLOYEES_VIEW_TEAM, Permission::PROJECTS_VIEW]);
        $this->assertFalse(Gate::forUser($bystander)->allows('view', $candidate));

        $recruiter = $this->userWith([Permission::CANDIDATES_VIEW]);
        $this->assertTrue(Gate::forUser($recruiter)->allows('view', $candidate));
    }

    /** Reading applicants is not deciding their candidacy. */
    public function test_reading_candidates_does_not_confer_changing_them(): void
    {
        $candidate = Candidate::create([
            'organization_id' => $this->org->id,
            'first_name' => 'Amina', 'last_name' => 'Otieno', 'email' => 'amina@example.com',
        ]);

        $reader = $this->userWith([Permission::CANDIDATES_VIEW]);

        $this->assertTrue(Gate::forUser($reader)->allows('view', $candidate));
        $this->assertFalse(Gate::forUser($reader)->allows('update', $candidate));
    }

    // ── Vendors ───────────────────────────────────────────────────────────

    /**
     * The one domain with a policy and no permissions behind it, so there was
     * nothing a role could grant.
     */
    public function test_the_new_vendor_permissions_actually_grant_access(): void
    {
        $vendor = VendorPartner::create([
            'organization_id' => $this->org->id, 'name' => 'Versa',
        ]);

        $nobody = $this->userWith([Permission::EMPLOYEES_VIEW]);
        $this->assertFalse(Gate::forUser($nobody)->allows('view', $vendor));

        $reader = $this->userWith([Permission::VENDORS_VIEW]);
        $this->assertTrue(Gate::forUser($reader)->allows('view', $vendor));
        $this->assertFalse(Gate::forUser($reader)->allows('update', $vendor));

        $manager = $this->userWith([Permission::VENDORS_MANAGE]);
        $this->assertTrue(Gate::forUser($manager)->allows('update', $vendor));
    }

    // ── Sensitive employee data ───────────────────────────────────────────

    /**
     * Salary and national ID had no permission behind them at all.
     *
     * The resource asked whether the caller's role was named HR, so the
     * permission screen could neither grant compensation to a payroll clerk nor
     * withhold it from anybody. It is now a permission, with the HR role kept
     * so that nothing changes for people who already had it.
     */
    public function test_sensitive_employee_fields_need_the_new_permission(): void
    {
        $subject = $this->userWith([Permission::EMPLOYEES_VIEW_OWN]);
        $subject->employee->update(['basic_salary' => 250000, 'national_id' => '12345678']);

        $url = "/api/v1/employees/{$subject->employee->id}?org={$this->org->slug}";

        // Reading the directory is not reading the payroll.
        Sanctum::actingAs($this->userWith([Permission::EMPLOYEES_VIEW]));
        $body = $this->getJson($url)->assertOk()->json('data');

        $this->assertArrayNotHasKey('basic_salary', $body);
        $this->assertArrayNotHasKey('national_id', $body);

        // With the permission, it is there.
        Sanctum::actingAs($this->userWith([
            Permission::EMPLOYEES_VIEW, Permission::EMPLOYEES_VIEW_SENSITIVE,
        ]));
        $this->getJson($url)->assertOk()->assertJsonPath('data.national_id', '12345678');
    }

    /** Nobody needs a permission to read their own record. */
    public function test_somebody_can_always_see_their_own_sensitive_details(): void
    {
        $user = $this->userWith([Permission::EMPLOYEES_VIEW_OWN]);
        $user->employee->update(['national_id' => '87654321']);

        Sanctum::actingAs($user);

        $this->getJson("/api/v1/employees/{$user->employee->id}?org={$this->org->slug}")
            ->assertOk()
            ->assertJsonPath('data.national_id', '87654321');
    }

    // ── Exiting somebody ──────────────────────────────────────────────────

    /**
     * Ending employment stops pay and access. It was gated on whether the
     * caller's role was named HR, so `employees.exit` granted nothing.
     */
    public function test_exiting_an_employee_answers_to_its_permission(): void
    {
        $subject = $this->userWith([Permission::EMPLOYEES_VIEW_OWN]);

        $this->assertFalse(
            Gate::forUser($this->userWith([Permission::EMPLOYEES_UPDATE]))
                ->allows('exit', $subject->employee),
        );

        $this->assertTrue(
            Gate::forUser($this->userWith([Permission::EMPLOYEES_EXIT]))
                ->allows('exit', $subject->employee),
        );
    }

    /** Every permission the policies name is one the catalogue publishes. */
    public function test_the_new_permissions_are_in_the_catalogue(): void
    {
        $all = Permission::all();

        $this->assertContains('vendors.view', $all);
        $this->assertContains('vendors.manage', $all);
    }
}
