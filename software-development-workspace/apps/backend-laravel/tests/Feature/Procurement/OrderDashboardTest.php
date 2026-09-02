<?php

namespace Tests\Feature\Procurement;

use App\Models\Organization;
use App\Models\Project\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The cross-project order dashboard on the Procurement module page. */
class OrderDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
    }

    private function actAs(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'T', 'scope' => 'organization', 'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);

        return $user;
    }

    private function seedOrders(Project $project): void
    {
        $this->postJson("/api/v1/projects/{$project->id}/bom/items?org={$this->org->slug}", ['category' => 'hardware', 'description' => 'Switch', 'quantity' => 1, 'unit_cost' => 100, 'selling_price' => 150])->assertCreated();
        $this->postJson("/api/v1/projects/{$project->id}/bom/items?org={$this->org->slug}", ['category' => 'software', 'description' => 'Licence', 'quantity' => 1, 'unit_cost' => 900, 'selling_price' => 1400])->assertCreated();
        $this->postJson("/api/v1/projects/{$project->id}/bom/approve?org={$this->org->slug}")->assertOk();
    }

    public function test_it_aggregates_orders_across_projects(): void
    {
        $this->actAs([Permission::PROJECTS_VIEW, Permission::PROJECTS_UPDATE, Permission::ASSETS_VIEW]);
        $a = Project::create(['organization_id' => $this->org->id, 'name' => 'Project A']);
        $b = Project::create(['organization_id' => $this->org->id, 'name' => 'Project B']);
        $this->seedOrders($a);
        $this->seedOrders($b);

        $res = $this->getJson("/api/v1/procurement/orders?org={$this->org->slug}")->assertOk();

        $this->assertSame(4, $res->json('summary.count'));       // 2 per project
        $this->assertSame(2, $res->json('summary.projects'));
        $this->assertSame(3100.0, (float) $res->json('summary.total_value'));   // (150+1400) ×2 projects
        $this->assertSame(2, $res->json('summary.by_category.hardware'));

        // Filter by project narrows it.
        $only = $this->getJson("/api/v1/procurement/orders?org={$this->org->slug}&project_id={$a->id}")->assertOk();
        $this->assertSame(2, $only->json('summary.count'));
    }

    public function test_it_requires_the_assets_view_permission(): void
    {
        $this->actAs([Permission::PROJECTS_VIEW]);   // no ASSETS_VIEW
        $this->getJson("/api/v1/procurement/orders?org={$this->org->slug}")->assertStatus(403);
    }
}
