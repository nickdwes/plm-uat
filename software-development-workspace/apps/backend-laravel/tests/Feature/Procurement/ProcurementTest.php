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

/** The procurement register — project surface and org-wide module. */
class ProcurementTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
        $this->project = Project::create(['organization_id' => $this->org->id, 'name' => 'SD-WAN']);
    }

    private function actor(array $permissions): void
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'T', 'scope' => 'organization', 'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);
    }

    public function test_a_project_item_appears_in_the_project_register_and_module(): void
    {
        $this->actor([Permission::PROJECTS_VIEW, Permission::PROJECTS_UPDATE, Permission::ASSETS_VIEW]);

        $res = $this->postJson("/api/v1/projects/{$this->project->id}/procurement?org={$this->org->slug}", [
            'item' => 'Cisco 9300', 'category' => 'hardware', 'quantity' => 12,
            'vendor' => 'Cisco', 'lead_time_days' => 45, 'estimated_cost' => 1800000,
        ])->assertOk();

        $row = $res->json('data.0');
        $this->assertMatchesRegularExpression('/^PR-/', $row['reference']);
        $this->assertTrue($row['is_long_lead']);              // 45 days ≥ 30
        $this->assertSame('pending', $row['approval_status']);
        $this->assertSame(1, $res->json('summary.long_lead'));

        // Same row shows in the org-wide module.
        $module = $this->getJson("/api/v1/procurement?org={$this->org->slug}")->assertOk();
        $this->assertCount(1, $module->json('data'));
        $this->assertSame(1800000, (int) $module->json('summary.estimated_total'));
    }

    public function test_an_item_can_be_approved(): void
    {
        $this->actor([Permission::PROJECTS_VIEW, Permission::PROJECTS_UPDATE, Permission::ASSETS_VIEW, Permission::ASSETS_UPDATE]);
        $id = $this->postJson("/api/v1/projects/{$this->project->id}/procurement?org={$this->org->slug}", ['item' => 'Licences'])
            ->json('data.0.id');

        $this->postJson("/api/v1/procurement/{$id}/decision?org={$this->org->slug}", ['approval_status' => 'approved'])
            ->assertOk()->assertJsonPath('data.approval_status', 'approved');
    }

    public function test_the_module_needs_the_assets_permission(): void
    {
        $this->actor([Permission::PROJECTS_VIEW]);   // no assets.view
        $this->getJson("/api/v1/procurement?org={$this->org->slug}")->assertForbidden();
    }
}
