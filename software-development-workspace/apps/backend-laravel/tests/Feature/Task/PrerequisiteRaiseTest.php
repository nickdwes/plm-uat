<?php

namespace Tests\Feature\Task;

use App\Models\Organization;
use App\Models\Procurement\ProcurementRequest;
use App\Models\Project\Project;
use App\Models\Role;
use App\Models\Task\TaskPrerequisite;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Phase-2 task logistics: prerequisites raise real records in the project registers. */
class PrerequisiteRaiseTest extends TestCase
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

    private function createTask(array $logistics): int
    {
        return $this->postJson($this->url('/tasks'), ['title' => 'Deploy', 'logistics' => $logistics])
            ->assertCreated()->json('data.id');
    }

    private function prereq(int $taskId, string $type): TaskPrerequisite
    {
        return TaskPrerequisite::where('task_id', $taskId)->where('type', $type)->firstOrFail();
    }

    public function test_raising_an_asset_prerequisite_creates_and_links_a_procurement_request(): void
    {
        $taskId = $this->createTask(['needs_asset' => true]);
        $p = $this->prereq($taskId, 'asset');
        $this->assertNull($p->linked_id);

        $res = $this->postJson($this->url("/tasks/{$taskId}/prerequisites/{$p->id}/raise"))->assertOk();

        $this->assertSame('procurement', $res->json('data.linked_kind'));
        $this->assertNotNull($res->json('data.linked_reference'));
        $this->assertSame('in_progress', $res->json('data.status'));
        $this->assertFalse($res->json('data.can_raise'));

        $pr = ProcurementRequest::where('project_id', $this->project->id)->where('task_id', $taskId)->first();
        $this->assertNotNull($pr);
        $this->assertSame('equipment', $pr->category);
        $this->assertSame('project', $pr->context);
    }

    public function test_raising_is_idempotent(): void
    {
        $taskId = $this->createTask(['needs_procurement' => true]);
        $p = $this->prereq($taskId, 'procurement');

        $this->postJson($this->url("/tasks/{$taskId}/prerequisites/{$p->id}/raise"))->assertOk();
        $this->postJson($this->url("/tasks/{$taskId}/prerequisites/{$p->id}/raise"))->assertOk();

        $this->assertSame(1, ProcurementRequest::where('task_id', $taskId)->count());
    }

    public function test_a_travel_prerequisite_cannot_raise_procurement(): void
    {
        $taskId = $this->createTask(['needs_travel' => true, 'travel_location' => 'Mombasa']);
        $p = $this->prereq($taskId, 'travel');

        // Travel is linked via cycle generation, not this raise endpoint.
        $this->postJson($this->url("/tasks/{$taskId}/prerequisites/{$p->id}/raise"))->assertStatus(422);
    }

    public function test_generating_travel_requests_links_the_travel_prerequisite(): void
    {
        $taskId = $this->createTask(['needs_travel' => true, 'travel_location' => 'Mombasa', 'travel_mode' => 'air']);

        $this->postJson($this->url('/travel-requests/generate'))->assertOk();

        $p = $this->prereq($taskId, 'travel')->fresh();
        $this->assertSame(\App\Models\Project\ProjectTravelRequest::class, $p->linked_type);
        $this->assertNotNull($p->linked_id);
        $this->assertSame('in_progress', $p->status);
    }

    public function test_a_linked_prerequisite_survives_its_flag_being_cleared(): void
    {
        $taskId = $this->createTask(['needs_asset' => true]);
        $p = $this->prereq($taskId, 'asset');
        $this->postJson($this->url("/tasks/{$taskId}/prerequisites/{$p->id}/raise"))->assertOk();

        // Clear the flag — a raised prerequisite must not vanish.
        $this->putJson($this->url("/tasks/{$taskId}"), ['title' => 'Deploy', 'logistics' => ['needs_asset' => false]])->assertOk();

        $this->assertNotNull(TaskPrerequisite::find($p->id));
    }
}
