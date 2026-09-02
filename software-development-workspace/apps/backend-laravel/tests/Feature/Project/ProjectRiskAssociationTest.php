<?php

namespace Tests\Feature\Project;

use App\Models\Organization;
use App\Models\Project\Project;
use App\Models\Project\ProjectPhase;
use App\Models\Role;
use App\Models\Task\Task;
use App\Models\User;
use App\Support\Account\DefaultReferenceData;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A risk tied to the work it threatens (a task and/or phase) and backed by
 * evidence documents.
 */
class ProjectRiskAssociationTest extends TestCase
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
        $this->project = Project::create(['organization_id' => $this->org->id, 'name' => 'SD-WAN']);
    }

    private function editor(): void
    {
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
        return "/api/v1/projects/{$this->project->id}/risks{$path}?org={$this->org->slug}";
    }

    public function test_a_risk_can_be_tied_to_a_task_and_a_phase(): void
    {
        $this->editor();
        $task = Task::create(['organization_id' => $this->org->id, 'project_id' => $this->project->id, 'title' => 'Cut-over']);
        $phase = ProjectPhase::create([
            'organization_id' => $this->org->id, 'project_id' => $this->project->id,
            'name' => 'Implementation', 'slug' => 'impl', 'position' => 0,
        ]);

        $res = $this->postJson($this->url(), [
            'title' => 'Cut-over may overrun', 'task_id' => $task->id, 'phase_id' => $phase->id,
        ])->assertCreated();

        $this->assertSame($task->id, $res->json('data.task.id'));
        $this->assertSame('Implementation', $res->json('data.phase.name'));
    }

    public function test_the_task_must_belong_to_the_project(): void
    {
        $this->editor();
        $other = Project::create(['organization_id' => $this->org->id, 'name' => 'Other']);
        $theirs = Task::create(['organization_id' => $this->org->id, 'project_id' => $other->id, 'title' => 'Theirs']);

        $this->postJson($this->url(), ['title' => 'x', 'task_id' => $theirs->id])
            ->assertStatus(422)->assertJsonValidationErrors('task_id');
    }

    public function test_evidence_attaches_to_a_risk(): void
    {
        $this->editor();
        $id = $this->postJson($this->url(), ['title' => 'ISP slip'])->json('data.id');

        $res = $this->postJson($this->url("/{$id}/evidence"), [
            'file' => UploadedFile::fake()->create('assessment.pdf', 30, 'application/pdf'),
        ])->assertCreated();

        $this->assertCount(1, $res->json('data.evidence'));
        $this->assertSame('assessment.pdf', $res->json('data.evidence.0.label'));
    }
}
