<?php

namespace Tests\Feature\Asset;

use App\Models\Asset\Asset;
use App\Models\Asset\AssetCategory;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Setting how a kind of thing is held.
 *
 * `custody_model` was added with the custodian work and seeded by migration,
 * and then no screen could set it: the store and update rules did not accept
 * it, so every category created afterwards silently defaulted to "carried".
 * A new core-switch category would have appeared in the assign picker and put
 * racked equipment on somebody's leaver checklist.
 */
class AssetCategoryCustodyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
    }

    private function signIn(array $permissions = ['assets.view', 'assets.create', 'assets.update']): void
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Test', 'scope' => 'organization', 'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/org/asset-categories{$path}?org={$this->org->slug}";
    }

    public function test_a_category_can_be_created_as_looked_after(): void
    {
        $this->signIn();

        $this->postJson($this->url(), [
            'asset_class'   => Asset::CLASS_PHYSICAL,
            'name'          => 'core switch',
            'custody_model' => AssetCategory::CUSTODY_CUSTODIAN,
        ])->assertSuccessful();

        $category = AssetCategory::where('name', 'core switch')->first();

        $this->assertSame(AssetCategory::CUSTODY_CUSTODIAN, $category->custody_model);
        $this->assertTrue($category->usesCustodian());
    }

    /** The 56 seeded as custodian were set by a migration nobody could correct. */
    public function test_the_custody_model_can_be_changed_afterwards(): void
    {
        $this->signIn();

        $category = AssetCategory::create([
            'organization_id' => $this->org->id,
            'asset_class'     => Asset::CLASS_PHYSICAL,
            'name'            => 'ups',
        ]);
        $this->assertSame(AssetCategory::CUSTODY_ASSIGNEE, $category->custody_model);

        $this->putJson($this->url("/{$category->id}"), [
            'custody_model' => AssetCategory::CUSTODY_CUSTODIAN,
        ])->assertOk();

        $this->assertSame(AssetCategory::CUSTODY_CUSTODIAN, $category->fresh()->custody_model);
    }

    /** Anything not one of the three would make usesCustodian() answer nonsense. */
    public function test_an_unknown_custody_model_is_refused(): void
    {
        $this->signIn();

        $this->postJson($this->url(), [
            'asset_class'   => Asset::CLASS_PHYSICAL,
            'name'          => 'nonsense',
            'custody_model' => 'borrowed',
        ])->assertStatus(422)->assertJsonValidationErrors('custody_model');
    }

    /**
     * The class exists on the backend and was missing from the configuration
     * screen's list, which left forty-four seeded categories invisible on the
     * only page that can edit them.
     */
    public function test_cloud_is_a_class_categories_can_be_created_under(): void
    {
        $this->signIn();

        $this->postJson($this->url(), [
            'asset_class'   => Asset::CLASS_CLOUD,
            'name'          => 'load balancer',
            'custody_model' => AssetCategory::CUSTODY_CUSTODIAN,
        ])->assertSuccessful();

        $this->assertSame(
            Asset::CLASS_CLOUD,
            AssetCategory::where('name', 'load balancer')->first()->asset_class,
        );
    }

    /** Left out, it keeps whatever it had rather than reverting to carried. */
    public function test_an_update_that_omits_it_leaves_it_alone(): void
    {
        $this->signIn();

        $category = AssetCategory::create([
            'organization_id' => $this->org->id,
            'asset_class'     => Asset::CLASS_CLOUD,
            'name'            => 'virtual machine',
            'custody_model'   => AssetCategory::CUSTODY_CUSTODIAN,
        ]);

        $this->putJson($this->url("/{$category->id}"), ['description' => 'Compute instances'])
            ->assertOk();

        $this->assertSame(AssetCategory::CUSTODY_CUSTODIAN, $category->fresh()->custody_model);
    }
}
