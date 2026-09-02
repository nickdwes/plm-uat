<?php

namespace Tests\Feature\Task;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Project\Project;
use App\Models\Project\ProjectPerson;
use App\Models\Role;
use App\Models\Task\Task;
use App\Models\Task\TaskEscalation;
use App\Models\Task\TaskPause;
use App\Models\User;
use App\Services\Task\TaskEscalationLadder;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Late work climbing somebody's desk — one rung at a time, once each. */
class TaskEscalationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
        $this->project = Project::create(['organization_id' => $this->org->id, 'name' => 'Rollout']);
    }

    private function employee(string $first = 'Test'): Employee
    {
        $user = User::factory()->create();

        return Employee::create([
            'employee_number'         => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id,
            'first_name' => $first, 'last_name' => 'Person',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
            'user_id' => $user->id,
        ]);
    }

    private function actor(): Employee
    {
        $employee = $this->employee('Actor');
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'T', 'scope' => 'organization',
            'permissions' => [Permission::TASKS_VIEW, Permission::TASKS_CREATE, Permission::TASKS_UPDATE],
        ]);
        $employee->user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $employee->user->forgetCachedRoles();
        Sanctum::actingAs($employee->user);

        return $employee;
    }

    private function task(array $attrs = []): Task
    {
        return Task::create($attrs + [
            'organization_id' => $this->org->id,
            'project_id'      => $this->project->id,
            'category'        => 'project',
            'title'           => 'Cut over the Nairobi link',
        ]);
    }

    private function ladder(): TaskEscalationLadder
    {
        return app(TaskEscalationLadder::class);
    }

    private function sweep(): array
    {
        return $this->ladder()->sweep($this->org->id);
    }

    // ── Overdue ───────────────────────────────────────────────────────────

    public function test_a_task_on_time_climbs_nothing(): void
    {
        $this->task(['deadline' => now()->addWeek()->toDateString(), 'assigned_to' => $this->employee()->id]);

        $this->assertSame(0, $this->sweep()['escalated']);
        $this->assertSame(0, TaskEscalation::count());
    }

    public function test_an_overdue_task_reaches_its_owner_then_the_project_manager(): void
    {
        $owner = $this->employee('Owner');
        $pm    = $this->employee('Manager');
        ProjectPerson::create([
            'organization_id' => $this->org->id, 'project_id' => $this->project->id,
            'employee_id' => $pm->id, 'role_kind' => ProjectPerson::KIND_PM,
        ]);

        $task = $this->task(['deadline' => now()->subDays(4)->toDateString(), 'assigned_to' => $owner->id]);

        $this->sweep();

        $rungs = TaskEscalation::where('task_id', $task->id)->pluck('to_employee_id', 'level');
        $this->assertSame($owner->id, $rungs[TaskEscalation::LEVEL_OWNER]);
        $this->assertSame($pm->id, $rungs[TaskEscalation::LEVEL_PM]);
        // Four days late has not reached the department head at seven.
        $this->assertArrayNotHasKey(TaskEscalation::LEVEL_DEPARTMENT, $rungs->all());
    }

    /**
     * An escalation that re-fires nightly is one people filter into a folder,
     * and then the ladder is decoration.
     */
    public function test_each_rung_fires_once(): void
    {
        $task = $this->task(['deadline' => now()->subDays(4)->toDateString(), 'assigned_to' => $this->employee()->id]);

        $first = $this->sweep()['escalated'];
        $again = $this->sweep()['escalated'];

        $this->assertGreaterThan(0, $first);
        $this->assertSame(0, $again, 'Nothing new to say the second night.');
        $this->assertSame($first, TaskEscalation::where('task_id', $task->id)->count());
    }

    public function test_it_keeps_climbing_as_the_task_gets_later(): void
    {
        $owner = $this->employee('Owner');
        $head  = $this->employee('Head');
        $department = Department::create([
            'organization_id' => $this->org->id, 'name' => 'Delivery', 'lead_employee_id' => $head->id,
        ]);
        $task = $this->task([
            'deadline' => now()->subDays(2)->toDateString(),
            'assigned_to' => $owner->id, 'department_id' => $department->id,
        ]);

        $this->sweep();
        $this->assertSame(1, TaskEscalation::where('task_id', $task->id)->count());

        // A week on, the next two rungs are reached.
        $task->update(['deadline' => now()->subDays(8)->toDateString()]);
        $this->sweep();

        $levels = TaskEscalation::where('task_id', $task->id)->pluck('level')->all();
        $this->assertContains(TaskEscalation::LEVEL_DEPARTMENT, $levels);
        $this->assertSame($head->id, TaskEscalation::where('task_id', $task->id)
            ->where('level', TaskEscalation::LEVEL_DEPARTMENT)->value('to_employee_id'));
    }

    /**
     * Waiting on a client's signature is not the team failing, and charging
     * that time to them argues the opposite of what happened.
     */
    public function test_a_paused_task_does_not_climb_the_overdue_ladder(): void
    {
        $task = $this->task(['deadline' => now()->subDays(10)->toDateString(), 'assigned_to' => $this->employee()->id]);
        TaskPause::create([
            'organization_id' => $this->org->id, 'task_id' => $task->id,
            'category' => 'pending_customer', 'reason' => 'awaiting_signoff',
            'responsible_party' => 'customer', 'paused_at' => now()->subDays(3),
        ]);

        $this->sweep();

        $this->assertSame(0, TaskEscalation::where('task_id', $task->id)
            ->where('trigger', TaskEscalation::TRIGGER_OVERDUE)->count());
    }

    public function test_a_finished_task_climbs_nothing(): void
    {
        $this->task([
            'deadline' => now()->subMonth()->toDateString(),
            'status' => Task::DONE, 'completed_at' => now(),
            'assigned_to' => $this->employee()->id,
        ]);

        $this->assertSame(0, $this->sweep()['escalated']);
    }

    // ── Pauses that overrun ───────────────────────────────────────────────

    public function test_a_wait_past_its_promised_date_climbs_its_own_ladder(): void
    {
        $owner = $this->employee('Owner');
        $task = $this->task(['assigned_to' => $owner->id]);
        TaskPause::create([
            'organization_id' => $this->org->id, 'task_id' => $task->id,
            'category' => 'pending_vendor', 'reason' => 'awaiting_delivery',
            'responsible_party' => 'vendor',
            'paused_at' => now()->subDays(12),
            'expected_resume_on' => now()->subDays(6)->toDateString(),
        ]);

        $this->sweep();

        $rows = TaskEscalation::where('task_id', $task->id)
            ->where('trigger', TaskEscalation::TRIGGER_PAUSE_OVERRUN)->get();
        $this->assertGreaterThanOrEqual(2, $rows->count(), 'Six days over reaches the owner and the PM.');
        $this->assertStringContainsString('vendor', $rows->first()->detail);
        $this->assertStringContainsString('due back', $rows->first()->detail);
    }

    public function test_a_pause_still_inside_its_promise_climbs_nothing(): void
    {
        $task = $this->task(['assigned_to' => $this->employee()->id]);
        TaskPause::create([
            'organization_id' => $this->org->id, 'task_id' => $task->id,
            'category' => 'pending_customer', 'reason' => 'awaiting_approval',
            'paused_at' => now()->subDay(),
            'expected_resume_on' => now()->addWeek()->toDateString(),
        ]);

        $this->assertSame(0, $this->sweep()['escalated']);
    }

    // ── Repeated slips ────────────────────────────────────────────────────

    public function test_a_deadline_moved_three_times_reaches_the_project_manager(): void
    {
        $pm = $this->employee('Manager');
        ProjectPerson::create([
            'organization_id' => $this->org->id, 'project_id' => $this->project->id,
            'employee_id' => $pm->id, 'role_kind' => ProjectPerson::KIND_PM,
        ]);
        $task = $this->task(['deadline' => now()->addMonth()->toDateString(), 'assigned_to' => $this->employee()->id]);

        foreach (range(1, 3) as $i) {
            $task->extensions()->create([
                'organization_id' => $this->org->id,
                'previous_deadline' => now()->addDays($i * 7)->toDateString(),
                'new_deadline' => now()->addDays(($i + 1) * 7)->toDateString(),
                'reason' => 'slip', 'impact' => 'some',
            ]);
        }

        $this->sweep();

        $row = TaskEscalation::where('task_id', $task->id)
            ->where('trigger', TaskEscalation::TRIGGER_REPEAT_SLIPS)->first();
        $this->assertNotNull($row);
        $this->assertSame($pm->id, $row->to_employee_id);
        $this->assertStringContainsString('moved 3 times', $row->detail);
    }

    public function test_two_slips_are_not_yet_a_pattern(): void
    {
        $task = $this->task(['deadline' => now()->addMonth()->toDateString(), 'assigned_to' => $this->employee()->id]);
        foreach (range(1, 2) as $i) {
            $task->extensions()->create([
                'organization_id' => $this->org->id,
                'new_deadline' => now()->addDays($i * 7)->toDateString(),
                'reason' => 'slip', 'impact' => 'some',
            ]);
        }

        $this->assertSame(0, $this->sweep()['escalated']);
    }

    // ── Targets ───────────────────────────────────────────────────────────

    /** A rung reached with nobody on it is worth knowing — it is a gap. */
    public function test_a_rung_with_nobody_on_it_is_still_recorded(): void
    {
        $task = $this->task(['deadline' => now()->subDays(4)->toDateString()]);

        $this->sweep();

        $owner = TaskEscalation::where('task_id', $task->id)
            ->where('level', TaskEscalation::LEVEL_OWNER)->firstOrFail();
        $this->assertNull($owner->to_employee_id, 'Unassigned, so nobody to tell — but the rung is on the record.');
    }

    /** Telling somebody about their own task is the same notification twice. */
    public function test_it_does_not_escalate_a_task_to_the_person_already_holding_it(): void
    {
        $owner = $this->employee('Owner');
        $department = Department::create([
            'organization_id' => $this->org->id, 'name' => 'Delivery', 'lead_employee_id' => $owner->id,
        ]);
        $task = $this->task([
            'deadline' => now()->subDays(10)->toDateString(),
            'assigned_to' => $owner->id, 'department_id' => $department->id,
        ]);

        $this->sweep();

        $this->assertNull(TaskEscalation::where('task_id', $task->id)
            ->where('level', TaskEscalation::LEVEL_DEPARTMENT)->value('to_employee_id'));
    }

    public function test_the_people_told_get_a_notification(): void
    {
        $owner = $this->employee('Owner');
        $this->task(['deadline' => now()->subDays(2)->toDateString(), 'assigned_to' => $owner->id]);

        $this->sweep();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $owner->user_id, 'type' => 'task.escalated',
        ]);
    }

    // ── The command and the payload ───────────────────────────────────────

    public function test_a_dry_run_changes_nothing(): void
    {
        $this->task(['deadline' => now()->subDays(4)->toDateString(), 'assigned_to' => $this->employee()->id]);

        $this->artisan('tasks:escalate', ['--dry-run' => true, '--org' => $this->org->id])
            ->assertSuccessful();

        $this->assertSame(0, TaskEscalation::count());
    }

    /**
     * A backlog is not news. Seeding records the rungs so the ladder starts
     * from now, instead of paging four people about each of several hundred
     * tasks that went late months ago.
     */
    public function test_seeding_records_the_backlog_without_telling_anybody(): void
    {
        $owner = $this->employee('Owner');
        $task = $this->task(['deadline' => now()->subDays(30)->toDateString(), 'assigned_to' => $owner->id]);

        $this->artisan('tasks:escalate', ['--seed' => true, '--org' => $this->org->id])->assertSuccessful();

        $this->assertGreaterThan(0, TaskEscalation::where('task_id', $task->id)->count());
        $this->assertDatabaseMissing('notifications', ['user_id' => $owner->user_id, 'type' => 'task.escalated']);

        // And having been seeded, it says nothing further tonight.
        $this->assertSame(0, $this->sweep()['escalated']);
    }

    public function test_the_task_reports_how_late_it_is_and_who_was_told(): void
    {
        $this->actor();
        $owner = $this->employee('Owner');
        $task = $this->task(['deadline' => now()->subDays(4)->toDateString(), 'assigned_to' => $owner->id]);

        $this->sweep();

        $data = $this->getJson("/api/v1/tasks/{$task->id}?org={$this->org->slug}")->assertOk()->json('data');
        $this->assertSame(4, $data['days_overdue']);
        $this->assertNotEmpty($data['escalations']);
        $this->assertSame('Past its deadline', $data['escalations'][0]['trigger_label']);
        $this->assertSame('Owner Person', collect($data['escalations'])
            ->firstWhere('level', TaskEscalation::LEVEL_OWNER)['to']);
    }
}
