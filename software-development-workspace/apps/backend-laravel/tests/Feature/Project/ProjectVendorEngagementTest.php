<?php

namespace Tests\Feature\Project;

use App\Models\Organization;
use App\Models\Project\Project;
use App\Models\Project\ProjectVendorEngagement;
use App\Models\Role;
use App\Models\Task\TaskPrerequisite;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Vendor engagements — the record a vendor/PS/subcontractor task requirement raises. */
class ProjectVendorEngagementTest extends TestCase
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

        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'T', 'scope' => 'organization',
            'permissions' => [Permission::PROJECTS_VIEW, Permission::PROJECTS_UPDATE, Permission::PROJECTS_ASSIGN],
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/projects/{$this->project->id}{$path}?org={$this->org->slug}";
    }

    public function test_a_subcontractor_prerequisite_raises_and_links_an_engagement(): void
    {
        $taskId = $this->postJson($this->url('/tasks'), ['title' => 'Cabling', 'logistics' => ['needs_subcontractors' => true]])
            ->assertCreated()->json('data.id');
        $p = TaskPrerequisite::where('task_id', $taskId)->where('type', 'subcontractor')->firstOrFail();

        $res = $this->postJson($this->url("/tasks/{$taskId}/prerequisites/{$p->id}/raise"))->assertOk();
        $this->assertSame('vendor', $res->json('data.linked_kind'));
        $this->assertNotNull($res->json('data.linked_reference'));

        $e = ProjectVendorEngagement::where('project_id', $this->project->id)->first();
        $this->assertSame('subcontractor', $e->engagement_type);
        $this->assertSame($taskId, $e->task_id);
        $this->assertSame('identifying', $e->status);
    }

    public function test_the_engagement_register_lists_and_updates(): void
    {
        $taskId = $this->postJson($this->url('/tasks'), ['title' => 'Advisory', 'logistics' => ['needs_professional_services' => true]])
            ->assertCreated()->json('data.id');
        $p = TaskPrerequisite::where('task_id', $taskId)->where('type', 'professional_services')->firstOrFail();
        $this->postJson($this->url("/tasks/{$taskId}/prerequisites/{$p->id}/raise"))->assertOk();

        $list = $this->getJson($this->url('/vendor-engagements'))->assertOk();
        $this->assertSame(1, $list->json('summary.count'));
        $id = $list->json('data.0.id');

        $upd = $this->putJson($this->url("/vendor-engagements/{$id}"), ['vendor_name' => 'Acme Consulting', 'status' => 'engaged'])->assertOk();
        $row = collect($upd->json('data'))->firstWhere('id', $id);
        $this->assertSame('Acme Consulting', $row['vendor']);
        $this->assertSame('engaged', $row['status']);
        $this->assertSame(0, $upd->json('summary.active'));   // engaged, not yet active
        $this->assertSame(1, $upd->json('summary.open'));     // still open (not closed)
    }

    public function test_raising_an_engagement_is_idempotent(): void
    {
        $taskId = $this->postJson($this->url('/tasks'), ['title' => 'Vendor bit', 'logistics' => ['needs_external_vendors' => true]])
            ->assertCreated()->json('data.id');
        $p = TaskPrerequisite::where('task_id', $taskId)->where('type', 'external_vendor')->firstOrFail();

        $this->postJson($this->url("/tasks/{$taskId}/prerequisites/{$p->id}/raise"))->assertOk();
        $this->postJson($this->url("/tasks/{$taskId}/prerequisites/{$p->id}/raise"))->assertOk();

        $this->assertSame(1, ProjectVendorEngagement::where('task_id', $taskId)->count());
    }
}
