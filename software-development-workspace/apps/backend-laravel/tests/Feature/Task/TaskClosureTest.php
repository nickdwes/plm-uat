<?php

namespace Tests\Feature\Task;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\Project\Project;
use App\Models\Role;
use App\Models\Task\Task;
use App\Models\Task\TaskClosure;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Nothing is finished until somebody says what came of it, and every task says
 * who it is waiting on.
 */
class TaskClosureTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
    }

    private function actor(array $permissions): Employee
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Test', 'scope' => 'organization', 'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();

        $employee = Employee::create([
            'employee_number'         => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id,
            'first_name' => 'Test', 'last_name' => 'Person',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
            'user_id' => $user->id,
        ]);
        Sanctum::actingAs($user);

        return $employee->fresh();
    }

    private function coordinator(): Employee
    {
        return $this->actor([
            Permission::TASKS_VIEW, Permission::TASKS_CREATE,
            Permission::TASKS_UPDATE, Permission::TASKS_DELETE,
            Permission::PROJECTS_VIEW, Permission::PROJECTS_UPDATE,
        ]);
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/tasks{$path}?org={$this->org->slug}";
    }

    private function task(array $attrs = []): Task
    {
        return Task::create($attrs + [
            'organization_id' => $this->org->id,
            'title'           => 'Renew the Fortinet support contract',
        ]);
    }

    private function summary(array $over = []): array
    {
        return $over + [
            'work_completed' => 'Renewal negotiated and the PO raised.',
            'deliverables'   => 'Signed renewal, updated asset register.',
            'challenges'     => 'The reseller quoted the wrong SKU twice.',
            'outcomes'       => 'Cover continues to March, at 4% less than last year.',
        ];
    }

    // ── The gate ──────────────────────────────────────────────────────────

    public function test_a_task_cannot_be_finished_without_saying_what_came_of_it(): void
    {
        $this->coordinator();
        $task = $this->task();

        $this->putJson($this->url("/{$task->id}"), ['title' => $task->title, 'status' => 'completed'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('closure');

        $this->assertSame('todo', $task->fresh()->status);
    }

    /**
     * The gate is on the state, not the door. This route can finish the same
     * task, and a rule only one of the two enforces is not a rule.
     */
    public function test_the_project_route_refuses_it_too(): void
    {
        $this->coordinator();
        $project = Project::create(['organization_id' => $this->org->id, 'name' => 'Rollout']);
        $task = $this->task(['project_id' => $project->id]);

        $this->putJson("/api/v1/projects/{$project->id}/tasks/{$task->id}?org={$this->org->slug}",
            ['title' => $task->title, 'status' => 'completed'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('closure');

        $this->assertSame('todo', $task->fresh()->status);
    }

    public function test_closing_it_off_records_the_summary_and_finishes_the_task(): void
    {
        $me = $this->coordinator();
        $task = $this->task();

        $this->postJson($this->url("/{$task->id}/closure"), $this->summary())
            ->assertOk()
            ->assertJsonPath('data.status', Task::DONE)
            ->assertJsonPath('data.is_done', true)
            ->assertJsonPath('data.needs_closure', false)
            ->assertJsonPath('data.closure.outcomes', 'Cover continues to March, at 4% less than last year.');

        $task->refresh();
        $this->assertNotNull($task->completed_at);
        $this->assertSame($me->id, $task->completed_by);

        $closure = TaskClosure::where('task_id', $task->id)->firstOrFail();
        $this->assertSame('Signed renewal, updated asset register.', $closure->deliverables);
        $this->assertNotNull($closure->closed_at);
    }

    public function test_every_required_field_is_required(): void
    {
        $this->coordinator();
        $task = $this->task();

        $this->postJson($this->url("/{$task->id}/closure"), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['work_completed', 'deliverables', 'challenges', 'outcomes']);
    }

    /** Plenty of tasks teach nobody anything; demanding a lesson invents one. */
    public function test_lessons_learned_is_optional(): void
    {
        $this->coordinator();
        $task = $this->task();

        $this->postJson($this->url("/{$task->id}/closure"), $this->summary())->assertOk();
        $this->assertNull(TaskClosure::where('task_id', $task->id)->value('lessons_learned'));
    }

    public function test_a_task_needing_sign_off_goes_to_the_approver_with_the_summary(): void
    {
        $this->coordinator();
        $approver = Employee::create([
            'employee_number' => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id,
            'first_name' => 'Ann', 'last_name' => 'Approver',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
        ]);
        $task = $this->task(['requires_approval' => true, 'approver_id' => $approver->id]);

        $this->postJson($this->url("/{$task->id}/closure"), $this->summary())
            ->assertOk()
            ->assertJsonPath('data.status', 'awaiting_approval');

        // Not finished — but the summary the approver reads is on the record.
        $this->assertNull($task->fresh()->completed_at);
        $this->assertNotNull(TaskClosure::where('task_id', $task->id)->first());
    }

    public function test_a_task_is_closed_off_once(): void
    {
        $this->coordinator();
        $task = $this->task();

        $this->postJson($this->url("/{$task->id}/closure"), $this->summary())->assertOk();
        $this->postJson($this->url("/{$task->id}/closure"), $this->summary())->assertStatus(422);

        $this->assertSame(1, TaskClosure::where('task_id', $task->id)->count());
    }

    /**
     * Reopening leaves the summary standing. It is a record of what was said at
     * the time, and the task can then be finished again without repeating it.
     */
    public function test_reopening_keeps_the_summary_and_lets_it_finish_again(): void
    {
        $this->coordinator();
        $task = $this->task();

        $this->postJson($this->url("/{$task->id}/closure"), $this->summary())->assertOk();
        $this->putJson($this->url("/{$task->id}"), ['title' => $task->title, 'status' => 'in_progress'])->assertOk();

        $this->assertNotNull(TaskClosure::where('task_id', $task->id)->first());
        $this->putJson($this->url("/{$task->id}"), ['title' => $task->title, 'status' => 'completed'])->assertOk();
        $this->assertSame(Task::DONE, $task->fresh()->status);
    }

    /**
     * Four paragraphs to tick off "book the room" is how a governance control
     * gets worked around instead of used. The parent carries the summary.
     */
    public function test_a_sub_task_is_ticked_off_without_a_summary(): void
    {
        $this->coordinator();
        $parent = $this->task();
        $sub = $this->task(['parent_task_id' => $parent->id, 'title' => 'Book the room']);

        $this->putJson($this->url("/{$sub->id}"), ['title' => $sub->title, 'status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('data.status', Task::DONE);

        $this->postJson($this->url("/{$sub->id}/closure"), $this->summary())->assertStatus(422);
    }

    // ── Responsibility ────────────────────────────────────────────────────

    public function test_a_task_says_who_it_is_waiting_on(): void
    {
        $this->coordinator();
        $task = $this->task();

        $this->getJson($this->url("/{$task->id}"))->assertOk()->assertJsonPath('data.responsibility', null);

        $this->putJson($this->url("/{$task->id}"), ['title' => $task->title, 'responsibility' => 'customer'])
            ->assertOk()
            ->assertJsonPath('data.responsibility', 'customer');
    }

    public function test_responsibility_only_takes_a_value_it_knows(): void
    {
        $this->coordinator();
        $task = $this->task();

        $this->putJson($this->url("/{$task->id}"), ['title' => $task->title, 'responsibility' => 'the_weather'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('responsibility');
    }

    /**
     * The two are different questions. The work is ours to deliver, and right
     * now it is sitting with the client — which is what lets a report say we
     * are late and whose fault the days were.
     */
    public function test_responsibility_is_not_the_same_as_who_owes_the_work(): void
    {
        $this->coordinator();
        $task = $this->task(['owner_party' => 'internal']);

        $this->putJson($this->url("/{$task->id}"), ['title' => $task->title, 'responsibility' => 'customer'])->assertOk();

        $fresh = $task->fresh();
        $this->assertTrue($fresh->is_ours, 'Still our work to deliver.');
        $this->assertSame('customer', $fresh->responsibility, 'But the client is holding it up.');
    }
}
