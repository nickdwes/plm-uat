<?php

namespace Tests\Feature\Project;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\Project\Project;
use App\Models\Project\ProjectPhase;
use App\Models\Role;
use App\Models\Task\Task;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What makes a payment milestone billable: the linked work is complete, and
 * proof of delivery is attached. Finance cannot invoice until both hold.
 */
class MilestoneReadinessTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha', 'currency' => 'KES']);
        app()->instance('current.organization_id', $this->org->id);

        $this->project = Project::create([
            'organization_id' => $this->org->id, 'name' => 'SD-WAN Rollout',
        ]);
    }

    private function editor(): void
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Test', 'scope' => 'organization',
            'permissions' => [Permission::PROJECTS_VIEW, Permission::PROJECTS_UPDATE],
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Employee::create([
            'employee_number' => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id, 'first_name' => 'T', 'last_name' => 'P',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01', 'user_id' => $user->id,
        ]);
        Sanctum::actingAs($user);
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/projects/{$this->project->id}{$path}?org={$this->org->slug}";
    }

    private function task(string $status = 'todo'): Task
    {
        return Task::create([
            'organization_id' => $this->org->id, 'project_id' => $this->project->id,
            'title' => 'Task', 'status' => $status,
        ]);
    }

    public function test_requirements_can_be_tasks_and_phases_and_track_completion(): void
    {
        $this->editor();
        $doneTask = $this->task('completed');
        $openTask = $this->task('todo');
        $phase = ProjectPhase::create([
            'organization_id' => $this->org->id, 'project_id' => $this->project->id,
            'name' => 'Design', 'slug' => 'design', 'position' => 1,
        ]);
        Task::create([
            'organization_id' => $this->org->id, 'project_id' => $this->project->id,
            'phase_id' => $phase->id, 'title' => 'Design task', 'status' => 'completed',
        ]);

        $res = $this->postJson($this->url('/milestones'), [
            'name' => 'On delivery of design', 'value' => 500000,
            'task_ids' => [$doneTask->id, $openTask->id], 'phase_ids' => [$phase->id],
        ])->assertCreated();

        $reqs = collect($res->json('data.requirements'));
        $this->assertCount(3, $reqs);
        $this->assertTrue($reqs->firstWhere('type', 'phase')['done']);
        // One open task means the milestone is not yet met.
        $this->assertFalse($res->json('data.requirements_met'));
        $this->assertFalse($res->json('data.ready_to_invoice'));
    }

    public function test_requirements_met_once_every_link_is_done(): void
    {
        $this->editor();
        $t = $this->task('completed');

        $res = $this->postJson($this->url('/milestones'), [
            'name' => 'M', 'value' => 1, 'task_ids' => [$t->id],
        ])->assertCreated();

        $this->assertTrue($res->json('data.requirements_met'));
        // But still not ready — no proof attached yet.
        $this->assertFalse($res->json('data.ready_to_invoice'));
        $this->assertFalse($res->json('data.has_proof'));
    }

    public function test_invoicing_is_blocked_until_work_done_and_proof_attached(): void
    {
        $this->editor();
        $t = $this->task('todo');
        $id = $this->postJson($this->url('/milestones'), [
            'name' => 'M', 'value' => 1, 'task_ids' => [$t->id],
        ])->json('data.id');

        // Work not done → blocked.
        $this->postJson($this->url("/milestones/{$id}/transition"), ['status' => 'invoiced'])
            ->assertStatus(422);

        $t->update(['status' => 'completed']);

        // Work done but no proof → still blocked.
        $this->postJson($this->url("/milestones/{$id}/transition"), ['status' => 'invoiced'])
            ->assertStatus(422);

        // Attach proof → now ready, and invoicing succeeds.
        $ready = $this->postJson($this->url("/milestones/{$id}/proofs"), [
            'file' => UploadedFile::fake()->create('acceptance.pdf', 40, 'application/pdf'),
        ])->assertCreated();
        $this->assertTrue($ready->json('data.has_proof'));
        $this->assertTrue($ready->json('data.ready_to_invoice'));
        $this->assertCount(1, $ready->json('data.proofs'));

        $this->postJson($this->url("/milestones/{$id}/transition"), ['status' => 'invoiced'])
            ->assertOk()->assertJsonPath('data.status', 'invoiced');
    }

    public function test_a_milestone_with_no_requirements_only_needs_proof(): void
    {
        $this->editor();
        $id = $this->postJson($this->url('/milestones'), ['name' => 'Deposit', 'value' => 1])->json('data.id');

        $this->assertNull($this->getJson($this->url('/milestones'))->json('data.0.requirements_met'));

        $this->postJson($this->url("/milestones/{$id}/transition"), ['status' => 'invoiced'])->assertStatus(422);

        $this->postJson($this->url("/milestones/{$id}/proofs"), [
            'file' => UploadedFile::fake()->create('deposit.pdf', 10, 'application/pdf'),
        ])->assertCreated();

        $this->postJson($this->url("/milestones/{$id}/transition"), ['status' => 'invoiced'])->assertOk();
    }
}
