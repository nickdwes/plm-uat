<?php

namespace Tests\Feature\Authorization;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Reading the organisation in context must never throw.
 *
 * `ResolveOrganizationContext` binds the key with `instance()`, and for
 * somebody operating across the whole group it binds **null** — meaning no
 * filter. The container tests for a bound instance with `isset()`, and
 * `isset()` is false for null, so a key deliberately bound to null looked
 * unbound and `app('current.organization_id')` tried to resolve it as a class.
 *
 * Two hundred and seventeen places read that key without guarding. The symptom
 * was a group administrator opening any list and getting a 500 — the one
 * account meant to see everything was the only one that could not.
 */
class OrganizationContextTest extends TestCase
{
    use RefreshDatabase;

    private Organization $alpha;
    private Organization $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        $this->beta  = Organization::create(['name' => 'Beta', 'slug' => 'beta']);

        foreach ([$this->alpha, $this->beta] as $org) {
            Employee::create([
                'employee_number' => 'E' . fake()->unique()->numberBetween(1000, 999999),
                'primary_organization_id' => $org->id,
                'first_name' => 'Person', 'last_name' => $org->name,
                'employment_type' => 'permanent', 'status' => 'active', 'date_hired' => '2024-01-01',
            ]);
        }
    }

    /** Somebody who oversees every organisation, granted at group scope. */
    private function groupAdministrator(): User
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Group Admin', 'scope' => 'group',
            'permissions' => [Permission::EMPLOYEES_VIEW],
        ]);

        // Null organisation is what group scope looks like in the grant table.
        $user->roles()->attach($role->id, ['organization_id' => null, 'scope' => 'group']);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);

        return $user;
    }

    /** The case that was a 500: no header, and no filter wanted. */
    public function test_a_group_administrator_can_list_without_naming_an_organisation(): void
    {
        $this->groupAdministrator();

        $this->getJson('/api/v1/employees')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    /** And can still narrow to one when they say which. */
    public function test_a_group_administrator_can_still_narrow_to_one(): void
    {
        $this->groupAdministrator();

        $this->getJson('/api/v1/employees', ['X-Organization' => 'alpha'])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.last_name', 'Alpha');
    }

    /** Reading the key directly is safe whether or not anything bound it. */
    public function test_the_context_reads_as_null_rather_than_throwing(): void
    {
        $this->assertNull(app('current.organization_id'));
        $this->assertNull(app('current.organization'));

        app()->instance('current.organization_id', null);
        $this->assertNull(app('current.organization_id'), 'a deliberate null must read back as null');

        app()->instance('current.organization_id', $this->alpha->id);
        $this->assertSame($this->alpha->id, app('current.organization_id'),
            'and a real organisation must still win');
    }
}
