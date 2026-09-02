<?php

namespace Tests\Feature\HR;

use App\Models\Department;
use App\Models\Employee;
use App\Models\OrgBusinessUnit;
use App\Models\OrgFunctionalUnit;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Who runs what, at every level.
 *
 * Departments, business units and practices have carried a lead column since
 * they were created; functional units — the level an escalation reaches when
 * a department head cannot resolve something — never had one. Live, not a
 * single head was set anywhere, which is why most task escalation rungs
 * reached nobody.
 */
class LeadershipTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
    }

    private function actor(array $permissions = [Permission::DEPARTMENTS_VIEW, Permission::DEPARTMENTS_MANAGE]): User
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Test', 'scope' => 'organization', 'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);

        return $user;
    }

    private function url(string $path): string
    {
        return "/api/v1{$path}?org={$this->org->slug}";
    }

    private function employee(string $first, string $last): Employee
    {
        return Employee::create([
            'employee_number' => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'organization_id' => $this->org->id,
            'primary_organization_id' => $this->org->id,
            'first_name' => $first, 'last_name' => $last,
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
        ]);
    }

    // ── The function head, which had nowhere to go ────────────────────────

    public function test_a_functional_unit_can_have_a_head(): void
    {
        $this->actor();
        $head = $this->employee('Grace', 'Mwende');

        $unit = $this->postJson($this->url('/org/functional-units'), [
            'name' => 'Technology', 'lead_employee_id' => $head->id,
        ])->assertStatus(201)->json();

        // The column the level above departments never had.
        $this->assertSame($head->id, OrgFunctionalUnit::findOrFail($unit['id'])->lead_employee_id);
    }

    public function test_a_head_from_another_organisation_is_refused(): void
    {
        $other = Organization::create(['name' => 'Beta', 'slug' => 'beta']);
        $stranger = Employee::create([
            'employee_number' => 'E999999', 'organization_id' => $other->id,
            'primary_organization_id' => $other->id,
            'first_name' => 'Not', 'last_name' => 'Ours',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
        ]);

        $this->actor();

        $this->postJson($this->url('/org/functional-units'), [
            'name' => 'Technology', 'lead_employee_id' => $stranger->id,
        ])->assertStatus(422);
    }

    // ── One answer for the whole structure ────────────────────────────────

    public function test_every_level_comes_back_with_its_head_and_its_parent(): void
    {
        $this->actor();

        $functionHead   = $this->employee('Grace', 'Mwende');
        $departmentHead = $this->employee('Peter', 'Otieno');

        $unit = OrgFunctionalUnit::create([
            'organization_id' => $this->org->id, 'name' => 'Technology',
            'lead_employee_id' => $functionHead->id,
        ]);
        $department = Department::create([
            'organization_id' => $this->org->id, 'name' => 'Infrastructure',
            'functional_unit_id' => $unit->id, 'lead_employee_id' => $departmentHead->id,
        ]);
        OrgBusinessUnit::create([
            'organization_id' => $this->org->id, 'name' => 'Data Centre',
            'department_id' => $department->id,
        ]);

        $body = $this->getJson($this->url('/org/leadership'))->assertOk()->json();

        $rows = collect($body['data']);

        $function = $rows->firstWhere('name', 'Technology');
        $this->assertSame('function', $function['level']);
        $this->assertSame('Grace Mwende', $function['lead']['name']);

        $dept = $rows->firstWhere('name', 'Infrastructure');
        $this->assertSame('Peter Otieno', $dept['lead']['name']);
        // Where it sits, so the list reads as a structure rather than a heap.
        $this->assertSame('Technology', $dept['parent']);

        $bu = $rows->firstWhere('name', 'Data Centre');
        $this->assertNull($bu['lead']);
        $this->assertSame('Infrastructure', $bu['parent']);

        // The number worth acting on.
        $this->assertSame(3, $body['meta']['total']);
        $this->assertSame(1, $body['meta']['unfilled']);
    }

    public function test_setting_a_head_removes_it_from_the_unfilled_count(): void
    {
        $this->actor();
        $head = $this->employee('Grace', 'Mwende');

        $unit = OrgFunctionalUnit::create([
            'organization_id' => $this->org->id, 'name' => 'Technology',
        ]);

        $this->assertSame(1, $this->getJson($this->url('/org/leadership'))->json('meta.unfilled'));

        $this->putJson($this->url("/org/functional-units/{$unit->id}"), [
            'lead_employee_id' => $head->id,
        ])->assertOk();

        $this->assertSame(0, $this->getJson($this->url('/org/leadership'))->json('meta.unfilled'));
    }

    public function test_a_head_can_be_cleared(): void
    {
        $this->actor();
        $head = $this->employee('Grace', 'Mwende');

        $unit = OrgFunctionalUnit::create([
            'organization_id' => $this->org->id, 'name' => 'Technology',
            'lead_employee_id' => $head->id,
        ]);

        // Somebody leaving the post is as ordinary as somebody taking it.
        $this->putJson($this->url("/org/functional-units/{$unit->id}"), [
            'lead_employee_id' => null,
        ])->assertOk();

        $this->assertNull($unit->fresh()->lead_employee_id);
    }

    public function test_another_tenants_structure_is_not_listed(): void
    {
        $other = Organization::create(['name' => 'Beta', 'slug' => 'beta']);
        OrgFunctionalUnit::create(['organization_id' => $other->id, 'name' => 'Their Function']);

        $this->actor();
        OrgFunctionalUnit::create(['organization_id' => $this->org->id, 'name' => 'Our Function']);

        $names = array_column($this->getJson($this->url('/org/leadership'))->json('data'), 'name');

        $this->assertContains('Our Function', $names);
        $this->assertNotContains('Their Function', $names);
    }

    // ── Authorization ─────────────────────────────────────────────────────

    public function test_reading_needs_view_and_setting_needs_manage(): void
    {
        $this->actor([Permission::DEPARTMENTS_VIEW]);

        $this->getJson($this->url('/org/leadership'))->assertOk();
        $this->postJson($this->url('/org/functional-units'), ['name' => 'Technology'])
            ->assertForbidden();
    }
}
