<?php

namespace Tests\Feature\HR;

use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Somebody placed in an organisation appears in it.
 *
 * The placement machinery already existed — a table with the department, the
 * manager, the allocation and the dates, an endpoint that wrote to it, and a
 * panel that called the endpoint. What it did not have was any reader: the
 * directory and the org chart both selected on `primary_organization_id`
 * alone, so a person seconded in at their whole week was invisible to
 * everybody except whoever typed the placement in.
 */
class CrossOrgDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private Organization $home;
    private Organization $host;
    private Employee $visitor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->home = Organization::create(['name' => 'Konvergenz', 'slug' => 'konvergenz']);
        $this->host = Organization::create(['name' => 'Tiberbu Healthnet', 'slug' => 'tiberbu']);

        $this->visitor = $this->employee('Wanjiru', 'Kamau', $this->home);
    }

    private function employee(string $first, string $last, Organization $org, array $attributes = []): Employee
    {
        return Employee::create(array_merge([
            'employee_number' => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $org->id,
            'first_name' => $first, 'last_name' => $last,
            'work_email' => strtolower($first) . fake()->unique()->numberBetween(1, 9999) . '@kns.co.ke',
            'employment_type' => 'permanent', 'status' => 'active', 'date_hired' => '2024-01-01',
        ], $attributes));
    }

    /** A placement row, as the secondment endpoint would write it. */
    private function place(Employee $employee, Organization $org, array $attributes = []): void
    {
        DB::table('employee_organization_map')->insert(array_merge([
            'employee_id' => $employee->id,
            'organization_id' => $org->id,
            'access_scope' => 'operational',
            'allocation_pct' => 60,
            'is_billable' => true,
            'effective_from' => now()->subMonth()->toDateString(),
            'effective_to' => null,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ], $attributes));
    }

    /** Sign in with access to one organisation. */
    private function signIn(Organization $org, array $permissions = null): User
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'HR', 'scope' => 'organization',
            'permissions' => $permissions ?? [
                Permission::EMPLOYEES_VIEW, Permission::EMPLOYEES_UPDATE,
                Permission::DEPARTMENTS_VIEW,
            ],
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);

        return $user;
    }

    private function directory(Organization $org): array
    {
        return $this->getJson("/api/v1/employees?org={$org->slug}")
            ->assertOk()->json('data');
    }

    // ── The directory ─────────────────────────────────────────────────────────

    /** The whole point: a placement makes somebody a colleague. */
    public function test_somebody_placed_in_an_organisation_is_listed_by_it(): void
    {
        $this->place($this->visitor, $this->host);
        $this->signIn($this->host);

        $names = collect($this->directory($this->host))->pluck('full_name');

        $this->assertContains('Wanjiru Kamau', $names->all());
    }

    /** And is still listed by the organisation that employs them. */
    public function test_a_placement_does_not_remove_them_from_home(): void
    {
        $this->place($this->visitor, $this->host);
        $this->signIn($this->home);

        $this->assertContains('Wanjiru Kamau',
            collect($this->directory($this->home))->pluck('full_name')->all());
    }

    /**
     * Visiting is said out loud.
     *
     * Nobody should have to work out whose payroll somebody is on by noticing
     * that their department looks unfamiliar.
     */
    public function test_a_visitor_is_labelled_with_the_organisation_that_employs_them(): void
    {
        $this->place($this->visitor, $this->host);
        $this->signIn($this->host);

        $row = collect($this->directory($this->host))->firstWhere('full_name', 'Wanjiru Kamau');

        $this->assertTrue($row['is_visiting']);
        $this->assertSame('Konvergenz', $row['home_organization']);
    }

    public function test_somebody_on_their_own_payroll_is_not_labelled_a_visitor(): void
    {
        $local = $this->employee('Otieno', 'Were', $this->host);
        $this->signIn($this->host);

        $row = collect($this->directory($this->host))->firstWhere('full_name', 'Otieno Were');

        $this->assertFalse($row['is_visiting']);
        $this->assertArrayNotHasKey('home_organization', $row);
    }

    /**
     * A visitor's job in the directory is the one their placement gives them.
     *
     * Their own department belongs to their home organisation and is
     * tenant-scoped, so reading it here returns nothing at all — the directory
     * showed a blank where the job should be.
     */
    public function test_the_directory_shows_a_visitor_their_host_department_and_title(): void
    {
        $homeDept = Department::create([
            'organization_id' => $this->home->id, 'name' => 'NetSec', 'code' => 'NETSEC',
        ]);
        $this->visitor->forceFill(['department_id' => $homeDept->id])->save();

        $hostDept = Department::create([
            'organization_id' => $this->host->id, 'name' => 'Clinical Systems', 'code' => 'CLIN',
        ]);
        $hostTitle = Designation::create([
            'organization_id' => $this->host->id, 'title' => 'Integration Engineer',
        ]);

        $this->place($this->visitor, $this->host, [
            'department_id' => $hostDept->id, 'designation_id' => $hostTitle->id,
        ]);

        $this->signIn($this->host);

        $row = collect($this->directory($this->host))->firstWhere('full_name', 'Wanjiru Kamau');

        $this->assertSame('Clinical Systems', $row['host_department']);
        $this->assertSame('Integration Engineer', $row['host_title']);
        $this->assertSame(60, $row['host_allocation_pct']);
    }

    /** Somebody employed here carries no placement overlay at all. */
    public function test_a_local_employee_has_no_host_overlay(): void
    {
        $this->employee('Otieno', 'Were', $this->host);
        $this->signIn($this->host);

        $row = collect($this->directory($this->host))->firstWhere('full_name', 'Otieno Were');

        $this->assertArrayNotHasKey('host_department', $row);
        $this->assertArrayNotHasKey('host_title', $row);
    }

    // ── The date window ───────────────────────────────────────────────────────

    /**
     * A placement that has ended is not membership.
     *
     * Checking the active flag alone leaves somebody in the directory of an
     * organisation they finished with in March.
     */
    public function test_a_placement_that_has_ended_is_not_membership(): void
    {
        $this->place($this->visitor, $this->host, [
            'effective_from' => now()->subYear()->toDateString(),
            'effective_to'   => now()->subMonth()->toDateString(),
        ]);
        $this->signIn($this->host);

        $this->assertNotContains('Wanjiru Kamau',
            collect($this->directory($this->host))->pluck('full_name')->all());
    }

    /** And one that has not started yet is not membership either. */
    public function test_a_placement_that_starts_later_is_not_yet_membership(): void
    {
        $this->place($this->visitor, $this->host, [
            'effective_from' => now()->addMonth()->toDateString(),
        ]);
        $this->signIn($this->host);

        $this->assertNotContains('Wanjiru Kamau',
            collect($this->directory($this->host))->pluck('full_name')->all());
    }

    public function test_a_placement_marked_inactive_is_not_membership(): void
    {
        $this->place($this->visitor, $this->host, ['is_active' => false]);
        $this->signIn($this->host);

        $this->assertNotContains('Wanjiru Kamau',
            collect($this->directory($this->host))->pluck('full_name')->all());
    }

    // ── Opening the record ────────────────────────────────────────────────────

    /** An organisation that lists somebody has to be able to open them. */
    public function test_the_host_can_open_the_record_of_somebody_placed_with_it(): void
    {
        $this->place($this->visitor, $this->host);
        $this->signIn($this->host);

        $this->getJson("/api/v1/employees/{$this->visitor->id}?org={$this->host->slug}")
            ->assertOk()
            ->assertJsonPath('data.full_name', 'Wanjiru Kamau');
    }

    /** An organisation they have nothing to do with still cannot. */
    public function test_an_unrelated_organisation_cannot_open_the_record(): void
    {
        $this->signIn($this->host);

        $this->getJson("/api/v1/employees/{$this->visitor->id}?org={$this->host->slug}")
            ->assertNotFound();
    }

    // ── The typeahead ─────────────────────────────────────────────────────────

    /**
     * The search endpoint had no organisation predicate at all.
     *
     * A typeahead for picking a colleague returned every name, work address and
     * employee number in the group to anybody who could reach the route.
     */
    public function test_search_does_not_reach_into_another_tenant(): void
    {
        $this->employee('Sensitive', 'Person', $this->home);
        $this->signIn($this->host);

        $found = collect($this->getJson("/api/v1/employees/search?q=Sensitive&org={$this->host->slug}")
            ->assertOk()->json('data'))->pluck('full_name');

        $this->assertNotContains('Sensitive Person', $found->all());
    }

    public function test_search_finds_somebody_placed_with_this_organisation(): void
    {
        $this->place($this->visitor, $this->host);
        $this->signIn($this->host);

        $found = collect($this->getJson("/api/v1/employees/search?q=Wanjiru&org={$this->host->slug}")
            ->assertOk()->json('data'))->pluck('full_name');

        $this->assertContains('Wanjiru Kamau', $found->all());
    }

    // ── The org chart ─────────────────────────────────────────────────────────

    /**
     * A visitor is drawn with the job they do *here*.
     *
     * Drawn from their own record they would appear under a manager who does
     * not work here, in a department this organisation has never heard of.
     */
    public function test_the_chart_shows_a_visitor_their_host_department_and_manager(): void
    {
        $hostManager = $this->employee('Amina', 'Yusuf', $this->host);

        $hostDept = Department::create([
            'organization_id' => $this->host->id, 'name' => 'Clinical Systems', 'code' => 'CLIN',
        ]);
        $hostTitle = Designation::create([
            'organization_id' => $this->host->id, 'title' => 'Integration Engineer',
        ]);

        $homeDept = Department::create([
            'organization_id' => $this->home->id, 'name' => 'NetSec', 'code' => 'NETSEC',
        ]);
        $this->visitor->forceFill(['department_id' => $homeDept->id])->save();

        $this->place($this->visitor, $this->host, [
            'department_id'  => $hostDept->id,
            'designation_id' => $hostTitle->id,
            'reports_to'     => $hostManager->id,
        ]);

        $this->signIn($this->host);

        $chart = $this->getJson("/api/v1/org/org-chart?org={$this->host->slug}")->assertOk()->json();

        $manager = collect($chart['reporting'])->firstWhere('name', 'Amina Yusuf');
        $this->assertNotNull($manager, 'the host manager should be a root of the chart');

        $child = collect($manager['children'])->firstWhere('name', 'Wanjiru Kamau');

        $this->assertNotNull($child, 'the visitor should hang off their host manager');
        $this->assertSame('Clinical Systems', $child['department']);
        $this->assertSame('Integration Engineer', $child['title']);
        $this->assertTrue($child['is_visiting']);
    }

    /**
     * A visitor with a host department is not "in no department".
     *
     * Their own `department_id` points at their home organisation's, so reading
     * it here would file a placed colleague under nobody.
     */
    public function test_a_placed_visitor_is_not_counted_as_unassigned(): void
    {
        $hostDept = Department::create([
            'organization_id' => $this->host->id, 'name' => 'Clinical Systems', 'code' => 'CLIN',
        ]);

        $this->place($this->visitor, $this->host, ['department_id' => $hostDept->id]);
        $this->signIn($this->host);

        $chart = $this->getJson("/api/v1/org/org-chart?org={$this->host->slug}")->assertOk()->json();

        $this->assertNotContains('Wanjiru Kamau', collect($chart['unassigned'])->pluck('name')->all());
        $this->assertSame(1, $chart['headcount']);
    }

    /** A placement naming no manager still shows up, as a root. */
    public function test_a_visitor_with_no_host_manager_becomes_a_root(): void
    {
        $this->place($this->visitor, $this->host, ['reports_to' => null]);
        $this->signIn($this->host);

        $chart = $this->getJson("/api/v1/org/org-chart?org={$this->host->slug}")->assertOk()->json();

        $this->assertContains('Wanjiru Kamau', collect($chart['reporting'])->pluck('name')->all());
    }
}
