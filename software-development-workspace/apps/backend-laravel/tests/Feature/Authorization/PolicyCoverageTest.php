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

    /** Every permission the policies name is one the catalogue publishes. */
    public function test_the_new_permissions_are_in_the_catalogue(): void
    {
        $all = Permission::all();

        $this->assertContains('vendors.view', $all);
        $this->assertContains('vendors.manage', $all);
    }
}
