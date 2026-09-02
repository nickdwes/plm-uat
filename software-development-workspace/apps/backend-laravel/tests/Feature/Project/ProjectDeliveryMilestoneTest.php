<?php

namespace Tests\Feature\Project;

use App\Models\Organization;
use App\Models\Project\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\Account\DefaultReferenceData;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Delivery milestones — dated deliverable checkpoints, distinct from the
 * payment (contract) milestones. What matters: no money on them, reaching
 * "achieved" stamps the date, and overdue is derived from the target date.
 */
class ProjectDeliveryMilestoneTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
        DefaultReferenceData::seedFor($this->org->id);

        $this->project = Project::create([
            'organization_id' => $this->org->id, 'name' => 'SD-WAN Rollout',
        ]);
    }

    private function actor(array $permissions): void
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
        return "/api/v1/projects/{$this->project->id}/delivery-milestones{$path}?org={$this->org->slug}";
    }

    public function test_a_delivery_milestone_is_added_with_a_target_date(): void
    {
        $this->actor([Permission::PROJECTS_VIEW, Permission::PROJECTS_UPDATE]);

        $res = $this->postJson($this->url(), [
            'title' => 'Design sign-off', 'target_date' => '2026-09-30',
        ])->assertCreated();

        $this->assertSame('planned', $res->json('data.status'));
        $this->assertSame('2026-09-30', $res->json('data.target_date'));
        $this->assertFalse($res->json('data.is_achieved'));
    }

    public function test_marking_achieved_stamps_the_date_and_clearing_it_removes_the_stamp(): void
    {
        $this->actor([Permission::PROJECTS_VIEW, Permission::PROJECTS_UPDATE]);

        $id = $this->postJson($this->url(), ['title' => 'Cut over CBD'])->json('data.id');

        $achieved = $this->putJson($this->url("/{$id}"), ['status' => 'achieved'])->assertOk();
        $this->assertTrue($achieved->json('data.is_achieved'));
        $this->assertNotNull($achieved->json('data.achieved_on'));

        $reopened = $this->putJson($this->url("/{$id}"), ['status' => 'in_progress'])->assertOk();
        $this->assertFalse($reopened->json('data.is_achieved'));
        $this->assertNull($reopened->json('data.achieved_on'));
    }

    public function test_overdue_is_derived_from_the_target_date(): void
    {
        $this->actor([Permission::PROJECTS_VIEW, Permission::PROJECTS_UPDATE]);

        $late = $this->postJson($this->url(), [
            'title' => 'Late deliverable', 'target_date' => now()->subWeek()->toDateString(),
        ])->assertCreated();
        $this->assertTrue($late->json('data.is_overdue'));

        // Achieving it clears overdue even though the date is past.
        $done = $this->putJson($this->url("/{$late->json('data.id')}"), ['status' => 'achieved'])->assertOk();
        $this->assertFalse($done->json('data.is_overdue'));
    }

    public function test_it_needs_the_update_permission_to_write(): void
    {
        $this->actor([Permission::PROJECTS_VIEW]);

        $this->postJson($this->url(), ['title' => 'x'])->assertForbidden();
    }
}
