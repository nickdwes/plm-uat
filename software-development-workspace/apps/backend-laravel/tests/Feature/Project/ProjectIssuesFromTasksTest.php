<?php

namespace Tests\Feature\Project;

use App\Models\Organization;
use App\Models\Project\Project;
use App\Models\Role;
use App\Models\Task\Task;
use App\Models\Task\TaskIssue;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The project Issues tab also surfaces issues raised on the project's tasks. */
class ProjectIssuesFromTasksTest extends TestCase
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
            'permissions' => [Permission::PROJECTS_VIEW, Permission::PROJECTS_UPDATE],
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/projects/{$this->project->id}{$path}?org={$this->org->slug}";
    }

    private function task(): Task
    {
        return Task::create([
            'organization_id' => $this->org->id, 'project_id' => $this->project->id,
            'title' => 'Cutover', 'reference' => 'TK-1', 'status' => 'in_progress', 'position' => 1,
        ]);
    }

    public function test_task_issues_appear_under_task_issues_but_not_the_project_list(): void
    {
        $task = $this->task();
        TaskIssue::create([
            'organization_id' => $this->org->id, 'task_id' => $task->id,
            'kind' => 'issue', 'name' => 'Switch DOA', 'priority' => 'high', 'status' => 'open',
        ]);
        // A task RISK must not leak into the issues list.
        TaskIssue::create([
            'organization_id' => $this->org->id, 'task_id' => $task->id,
            'kind' => 'risk', 'name' => 'Weather delay', 'priority' => 'medium', 'status' => 'open',
        ]);

        $res = $this->getJson($this->url('/issues'))->assertOk();

        $this->assertCount(0, $res->json('data'));          // no project-level issues
        $this->assertCount(1, $res->json('task_issues'));   // the task issue, not the risk
        $ti = $res->json('task_issues.0');
        $this->assertSame('Switch DOA', $ti['title']);
        $this->assertSame('TK-1', $ti['task']['reference']);
        $this->assertSame('Cutover', $ti['task']['title']);
    }

    public function test_a_task_issue_from_another_project_is_not_shown(): void
    {
        $other = Project::create(['organization_id' => $this->org->id, 'name' => 'Other']);
        $otherTask = Task::create([
            'organization_id' => $this->org->id, 'project_id' => $other->id,
            'title' => 'X', 'status' => 'open', 'position' => 1,
        ]);
        TaskIssue::create([
            'organization_id' => $this->org->id, 'task_id' => $otherTask->id,
            'kind' => 'issue', 'name' => 'Not mine', 'priority' => 'low', 'status' => 'open',
        ]);

        $res = $this->getJson($this->url('/issues'))->assertOk();
        $this->assertCount(0, $res->json('task_issues'));
    }
}
