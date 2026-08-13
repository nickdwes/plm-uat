<?php

namespace Tests\Feature\Asset;

use App\Models\Asset\Asset;
use App\Models\Asset\AssetCategory;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The custodian, over HTTP.
 *
 * These exist because the service worked and the screen still showed "nobody
 * answerable" straight after saving one: the detail endpoint did not eager-load
 * the relation, and a resource only emits what was loaded. The register writing
 * something nothing can read is the recurring failure in this module, and it is
 * only ever caught end to end.
 */
class AssetCustodianEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Asset $vm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);

        $category = AssetCategory::create([
            'organization_id' => $this->org->id,
            'asset_class'     => Asset::CLASS_CLOUD,
            'name'            => 'virtual machine',
            'custody_model'   => AssetCategory::CUSTODY_CUSTODIAN,
        ]);

        $this->vm = Asset::create([
            'organization_id'   => $this->org->id,
            'asset_class'       => Asset::CLASS_CLOUD,
            'asset_category_id' => $category->id,
            'category'          => 'virtual machine',
            'name'              => 'KonPlm',
            'asset_tag'         => 'AZ-TEST01',
        ]);
    }

    private function signIn(array $permissions = ['assets.view', 'assets.assign']): Employee
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Test', 'scope' => 'organization', 'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);

        return $this->employee();
    }

    private function employee(string $first = 'Grace'): Employee
    {
        return Employee::create([
            'employee_number'         => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id,
            'first_name' => $first, 'last_name' => 'Njoroge',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
        ]);
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/assets/{$this->vm->id}{$path}?org={$this->org->slug}";
    }

    /**
     * The bug this file exists for: saved, then invisible.
     */
    public function test_a_saved_custodian_is_readable_from_the_detail_endpoint(): void
    {
        $this->signIn();
        $grace = $this->employee('Grace');

        $this->postJson($this->url('/custodian'), ['employee_id' => $grace->id])
            ->assertOk()
            ->assertJsonPath('data.custodian.full_name', 'Grace Njoroge');

        // The screen reloads after saving, so the show endpoint has to carry it
        // too — this is exactly where it was lost.
        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.custodian.full_name', 'Grace Njoroge')
            ->assertJsonPath('data.uses_custodian', true)
            ->assertJsonPath('data.custodian_since', now()->toDateString());
    }

    public function test_a_custodian_can_be_cleared(): void
    {
        $this->signIn();
        $grace = $this->employee('Grace');

        $this->postJson($this->url('/custodian'), ['employee_id' => $grace->id])->assertOk();

        $this->deleteJson($this->url('/custodian'))
            ->assertOk()
            ->assertJsonPath('data.custodian', null);

        // Still running: infrastructure nobody is answerable for is not
        // infrastructure that stopped.
        $this->assertSame(Asset::STATUS_IN_SERVICE, $this->vm->fresh()->status);
    }

    /** The same tenancy check assignment carries; `exists` knows nothing of orgs. */
    public function test_an_employee_from_another_organization_is_refused(): void
    {
        $this->signIn();

        $other = Organization::create(['name' => 'Beta', 'slug' => 'beta']);
        $stranger = Employee::create([
            'employee_number'         => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $other->id,
            'first_name' => 'Someone', 'last_name' => 'Else',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
        ]);

        $this->postJson($this->url('/custodian'), ['employee_id' => $stranger->id])
            ->assertStatus(422);
    }

    public function test_setting_a_custodian_needs_the_assign_permission(): void
    {
        $this->signIn(['assets.view']);
        $grace = $this->employee();

        $this->postJson($this->url('/custodian'), ['employee_id' => $grace->id])
            ->assertForbidden();
    }
}
