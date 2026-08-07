<?php

namespace Tests\Feature\Attendance;

use App\Models\Attendance\AttendanceBlock;
use App\Models\Attendance\AttendanceDay;
use App\Models\Attendance\AttendancePolicy;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Project\Project;
use App\Models\Role;
use App\Models\Task\Task;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Planning a day.
 *
 * The designs settle the question this module could have got wrong. The Add
 * Task modal offers Project and Task as dropdowns, not text fields — so a block
 * gives existing work a slot rather than writing a second copy of it where the
 * Tasks module cannot see. What is proved here is chiefly that: the plan and
 * the task list are one set of records, not two that quietly disagree.
 */
class DayPlanningTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);

        AttendancePolicy::forOrganization($this->org->id);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function staff(): Employee
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Test', 'scope' => 'organization',
            'permissions' => [
                Permission::ATTENDANCE_VIEW_OWN, Permission::ATTENDANCE_RECORD,
                Permission::TASKS_VIEW, Permission::TASKS_UPDATE,
            ],
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();

        $employee = Employee::create([
            'employee_number'         => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id,
            'first_name' => 'Test', 'last_name' => 'Person',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
        ]);
        $employee->update(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        return $employee->fresh();
    }

    private function task(array $attrs = []): Task
    {
        return Task::create($attrs + [
            'organization_id' => $this->org->id,
            'title'           => 'Redesign messaging component library',
        ]);
    }

    private function url(string $path = '', array $query = []): string
    {
        $q = http_build_query($query + ['org' => $this->org->slug]);

        return "/api/v1/attendance{$path}?{$q}";
    }

    private function today(): string
    {
        return now()->toDateString();
    }

    private function block(array $body): \Illuminate\Testing\TestResponse
    {
        return $this->postJson($this->url('/blocks'), $body + ['work_date' => $this->today()]);
    }

    // ── A block gives existing work a slot ────────────────────────────────

    public function test_planning_a_task_puts_it_in_the_day(): void
    {
        $this->staff();
        $task = $this->task();

        $this->block(['task_id' => $task->id, 'starts_at' => '08:00', 'ends_at' => '10:00'])
            ->assertOk()
            ->assertJsonPath('data.plan.0.title', 'Redesign messaging component library')
            ->assertJsonPath('data.plan.0.task.id', $task->id)
            ->assertJsonPath('data.plan.0.minutes', 120);
    }

    /**
     * Planning does not create work. If it did, the task list would fill with
     * a second copy of everything anybody ever scheduled.
     */
    public function test_planning_a_day_creates_no_tasks(): void
    {
        $this->staff();
        $task = $this->task();

        $this->block(['task_id' => $task->id, 'starts_at' => '08:00', 'ends_at' => '10:00'])->assertOk();
        $this->block(['title' => 'Lunch', 'kind' => 'break', 'starts_at' => '13:00', 'ends_at' => '14:00'])
            ->assertOk();

        $this->assertSame(1, Task::count());
    }

    /**
     * Break and Lunch are real parts of a day. Forcing a task behind each one
     * would fill the task list with rubbish nobody wants to see.
     */
    public function test_a_block_need_not_be_a_task(): void
    {
        $this->staff();

        $this->block(['title' => 'Lunch', 'kind' => 'break', 'starts_at' => '13:00', 'ends_at' => '14:00'])
            ->assertOk()
            ->assertJsonPath('data.plan.0.title', 'Lunch')
            ->assertJsonPath('data.plan.0.task', null);
    }

    public function test_a_block_with_neither_a_task_nor_a_title_is_refused(): void
    {
        $this->staff();

        $this->block(['starts_at' => '13:00', 'ends_at' => '14:00'])
            ->assertJsonValidationErrorFor('title');
    }

    public function test_a_block_must_end_after_it_starts(): void
    {
        $this->staff();

        $this->block(['title' => 'Impossible', 'starts_at' => '14:00', 'ends_at' => '13:00'])
            ->assertJsonValidationErrorFor('ends_at');
    }

    /**
     * The title is copied rather than read through the task, so the timeline
     * still reads after a rename — and so one task split across a morning and
     * an afternoon can say which half is which.
     */
    public function test_the_title_survives_the_task_being_renamed(): void
    {
        $this->staff();
        $task = $this->task();

        $this->block(['task_id' => $task->id, 'starts_at' => '08:00', 'ends_at' => '10:00'])->assertOk();

        $task->update(['title' => 'Something else entirely']);

        $this->getJson($this->url('', ['from' => $this->today(), 'to' => $this->today()]))
            ->assertOk()
            ->assertJsonPath('data.0.plan.0.title', 'Redesign messaging component library');
    }

    public function test_a_block_can_be_given_its_own_title_over_the_task_name(): void
    {
        $this->staff();
        $task = $this->task();

        $this->block([
            'task_id' => $task->id, 'title' => 'Component library — morning',
            'starts_at' => '08:00', 'ends_at' => '10:00',
        ])->assertOk()->assertJsonPath('data.plan.0.title', 'Component library — morning');
    }

    // ── Moving and removing ───────────────────────────────────────────────

    public function test_a_block_can_be_moved(): void
    {
        $this->staff();

        $this->block(['title' => 'Review', 'starts_at' => '11:00', 'ends_at' => '12:00'])->assertOk();
        $block = AttendanceBlock::firstOrFail();

        $this->putJson($this->url("/blocks/{$block->id}"), ['starts_at' => '15:00', 'ends_at' => '16:00'])
            ->assertOk()
            ->assertJsonPath('data.plan.0.starts_at', '15:00');
    }

    /** Moving one end must not be able to invert the span. */
    public function test_a_move_that_would_invert_the_block_is_refused(): void
    {
        $this->staff();

        $this->block(['title' => 'Review', 'starts_at' => '11:00', 'ends_at' => '12:00'])->assertOk();
        $block = AttendanceBlock::firstOrFail();

        $this->putJson($this->url("/blocks/{$block->id}"), ['starts_at' => '13:00'])->assertStatus(422);

        $this->assertSame('11:00', substr((string) $block->fresh()->starts_at, 0, 5));
    }

    public function test_a_block_can_be_removed(): void
    {
        $this->staff();

        $this->block(['title' => 'Review', 'starts_at' => '11:00', 'ends_at' => '12:00'])->assertOk();

        $this->deleteJson($this->url('/blocks/' . AttendanceBlock::firstOrFail()->id))
            ->assertOk()
            ->assertJsonCount(0, 'data.plan');
    }

    /** A slot for work that no longer exists is a slot for nothing. */
    public function test_deleting_a_task_takes_its_blocks_with_it(): void
    {
        $this->staff();
        $task = $this->task();

        $this->block(['task_id' => $task->id, 'starts_at' => '08:00', 'ends_at' => '10:00'])->assertOk();

        $task->forceDelete();

        $this->assertSame(0, AttendanceBlock::count());
    }

    // ── Clashes ───────────────────────────────────────────────────────────

    /**
     * Flagged rather than refused. A planner that will not let you write down a
     * clash is one people stop using — but a clash nobody is shown is a plan
     * that quietly cannot happen.
     */
    public function test_overlapping_blocks_are_flagged_and_still_accepted(): void
    {
        $this->staff();

        $this->block(['title' => 'Team sync', 'starts_at' => '10:00', 'ends_at' => '11:00'])->assertOk();

        $this->block(['title' => 'One on one', 'starts_at' => '10:30', 'ends_at' => '11:30'])
            ->assertOk()
            ->assertJsonPath('data.plan.0.clashes', true)
            ->assertJsonPath('data.plan.1.clashes', true);
    }

    /** Back to back is not a clash — one ends exactly where the next begins. */
    public function test_blocks_that_merely_touch_do_not_clash(): void
    {
        $this->staff();

        $this->block(['title' => 'Standup', 'starts_at' => '09:00', 'ends_at' => '09:30'])->assertOk();

        $this->block(['title' => 'Build', 'starts_at' => '09:30', 'ends_at' => '11:00'])
            ->assertOk()
            ->assertJsonPath('data.plan.0.clashes', false)
            ->assertJsonPath('data.plan.1.clashes', false);
    }

    // ── The productivity pair ─────────────────────────────────────────────

    /**
     * A task split across a morning and an afternoon is one task planned, not
     * two — otherwise the figure rewards fragmenting the day.
     */
    public function test_a_task_planned_twice_counts_once(): void
    {
        $this->staff();
        $task = $this->task();

        $this->block(['task_id' => $task->id, 'starts_at' => '08:00', 'ends_at' => '10:00'])->assertOk();
        $this->block(['task_id' => $task->id, 'starts_at' => '14:00', 'ends_at' => '16:00'])
            ->assertOk()
            ->assertJsonPath('data.tasks.planned', 1)
            ->assertJsonPath('data.planned_minutes', 240);
    }

    /** Breaks are part of the day but not part of the work planned. */
    public function test_blocks_with_no_task_are_not_counted_as_planned_work(): void
    {
        $this->staff();

        $this->block(['title' => 'Lunch', 'kind' => 'break', 'starts_at' => '13:00', 'ends_at' => '14:00'])
            ->assertOk()
            ->assertJsonPath('data.tasks.planned', 0);
    }

    /**
     * Read from the tasks themselves rather than a counter, so it cannot drift
     * from the records it describes.
     */
    public function test_finishing_a_planned_task_is_counted_as_completed_today(): void
    {
        $this->staff();
        $task = $this->task();

        $this->block(['task_id' => $task->id, 'starts_at' => '08:00', 'ends_at' => '10:00'])
            ->assertOk()
            ->assertJsonPath('data.tasks.completed', 0);

        $task->update(['status' => Task::DONE, 'completed_at' => now()]);

        $this->getJson($this->url('', ['from' => $this->today(), 'to' => $this->today()]))
            ->assertOk()
            ->assertJsonPath('data.0.tasks.completed', 1);
    }

    /** Finished on a different day is not finished today. */
    public function test_a_task_finished_on_another_day_is_not_counted_today(): void
    {
        $this->staff();
        $task = $this->task(['status' => Task::DONE, 'completed_at' => now()->subWeek()]);

        $this->block(['task_id' => $task->id, 'starts_at' => '08:00', 'ends_at' => '10:00'])
            ->assertOk()
            ->assertJsonPath('data.tasks.completed', 0);
    }

    // ── Planning ahead ────────────────────────────────────────────────────

    /**
     * Planning next Tuesday should not require the caller to create Tuesday
     * first. The day is made if it is not there.
     */
    public function test_planning_a_future_day_creates_it(): void
    {
        $this->staff();
        $date = now()->addDays(4)->toDateString();

        $this->postJson($this->url('/blocks'), [
            'work_date' => $date, 'title' => 'Client presentation prep',
            'starts_at' => '09:00', 'ends_at' => '11:00',
        ])->assertOk();

        $this->assertDatabaseHas('attendance_days', ['work_date' => $date]);
    }

    public function test_a_week_of_days_comes_back_with_its_plan(): void
    {
        $this->staff();

        foreach ([0, 1, 2] as $offset) {
            $this->postJson($this->url('/blocks'), [
                'work_date' => now()->addDays($offset)->toDateString(),
                'title' => 'Focus block', 'starts_at' => '09:00', 'ends_at' => '11:00',
            ])->assertOk();
        }

        $this->getJson($this->url('', [
            'from' => now()->toDateString(), 'to' => now()->addDays(6)->toDateString(),
        ]))->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.plan.0.title', 'Focus block');
    }

    // ── Whose day ─────────────────────────────────────────────────────────

    /** Planning somebody else's day is a claim about what they will be doing. */
    public function test_planning_another_persons_day_is_refused(): void
    {
        $this->staff();

        $other = Employee::create([
            'employee_number' => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id,
            'first_name' => 'Other', 'last_name' => 'Person',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
        ]);

        $this->postJson($this->url('/blocks'), [
            'work_date' => $this->today(), 'employee_id' => $other->id,
            'title' => 'Not mine to plan', 'starts_at' => '09:00', 'ends_at' => '10:00',
        ])->assertForbidden();
    }

    /** A block against work in another organisation is not a block at all. */
    public function test_a_task_from_another_organisation_cannot_be_planned(): void
    {
        $this->staff();

        $other = Organization::create(['name' => 'Beta', 'slug' => 'beta']);
        $task  = Task::withoutGlobalScopes()->create([
            'organization_id' => $other->id, 'title' => 'Somebody else’s work',
        ]);

        $this->block(['task_id' => $task->id, 'starts_at' => '08:00', 'ends_at' => '10:00'])
            ->assertJsonValidationErrorFor('task_id');
    }

    // ── Planned against worked ────────────────────────────────────────────

    /**
     * "Planned nine hours, worked seven" is the comparison the productivity
     * requirement reaches for, and it needs both halves to exist.
     */
    public function test_planned_time_sits_beside_time_actually_worked(): void
    {
        $this->staff();

        $this->block(['title' => 'Deep work', 'starts_at' => '09:00', 'ends_at' => '17:00'])->assertOk();

        $this->postJson($this->url('/add-hours'), [
            'work_date' => $this->today(), 'from' => '09:00', 'to' => '15:00',
        ])->assertOk();

        $this->getJson($this->url('', ['from' => $this->today(), 'to' => $this->today()]))
            ->assertOk()
            ->assertJsonPath('data.0.planned_minutes', 480)
            ->assertJsonPath('data.0.worked_minutes', 360);
    }
}
