<?php

namespace Tests\Feature\Task;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\Project\Project;
use App\Models\Role;
use App\Models\Task\Task;
use App\Models\Task\TaskIssue;
use App\Models\Task\TaskPause;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Deadlines that move and clocks that stop — the two ways a task slips, and
 * what each of them has to leave behind.
 */
class TaskDelayTest extends TestCase
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
            'project_id'      => $this->project->id,
            'category'        => 'project',
            'title'           => 'Cut over the Nairobi link',
            'deadline'        => now()->addWeek()->toDateString(),
        ]);
    }

    private function extension(array $over = []): array
    {
        return $over + [
            'new_deadline' => now()->addWeeks(3)->toDateString(),
            'reason'       => 'The client moved the change window.',
            'impact'       => 'Pushes UAT a week; no effect on the go-live date.',
        ];
    }

    // ── Deadline extensions ───────────────────────────────────────────────

    public function test_moving_a_deadline_keeps_the_date_it_replaced(): void
    {
        $this->coordinator();
        $was = now()->addWeek()->toDateString();
        $task = $this->task(['deadline' => $was]);
        $to = now()->addWeeks(3)->toDateString();

        $res = $this->postJson($this->url("/{$task->id}/extensions"), $this->extension(['new_deadline' => $to]))
            ->assertOk()
            ->assertJsonPath('data.deadline', $to)
            ->assertJsonPath('data.extension_count', 1);

        $e = collect($res->json('data.extensions'))->first();
        $this->assertSame($was, $e['previous_deadline'], 'The date it moved from is the whole point.');
        $this->assertSame($to, $e['new_deadline']);
        $this->assertSame(14, $e['days_moved']);
    }

    public function test_every_slip_is_counted(): void
    {
        $this->coordinator();
        $task = $this->task();

        foreach ([2, 4, 6] as $weeks) {
            $this->postJson($this->url("/{$task->id}/extensions"),
                $this->extension(['new_deadline' => now()->addWeeks($weeks)->toDateString()]))->assertOk();
        }

        $this->assertSame(3, $this->getJson($this->url("/{$task->id}"))->assertOk()->json('data.extension_count'));
    }

    public function test_the_reason_and_the_impact_are_not_optional(): void
    {
        $this->coordinator();
        $task = $this->task();

        $this->postJson($this->url("/{$task->id}/extensions"), ['new_deadline' => now()->addMonth()->toDateString()])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason', 'impact']);
    }

    /** An "extension" that brings a date forward is a correction, not a slip. */
    public function test_an_extension_has_to_move_the_date_later(): void
    {
        $this->coordinator();
        $task = $this->task(['deadline' => now()->addWeeks(4)->toDateString()]);

        $this->postJson($this->url("/{$task->id}/extensions"),
            $this->extension(['new_deadline' => now()->addWeek()->toDateString()]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('new_deadline');
    }

    /**
     * A defect found after the fact is exactly when somebody needs to record
     * one, so finishing a task does not close the door on raising an issue.
     * Pause and Extend are refused on a finished task; this is not like them,
     * and the panel should not be stricter than the rule it enforces.
     */
    public function test_an_issue_can_still_be_raised_against_a_finished_task(): void
    {
        $this->coordinator();
        $task = $this->task(['status' => Task::DONE, 'completed_at' => now()]);

        $this->postJson($this->url("/{$task->id}/issues"), [
            'name' => 'Config drifted back a week after handover',
        ])->assertOk();

        $this->assertSame(1, TaskIssue::where('task_id', $task->id)->count());

        // But the two that need live work are still refused.
        $this->postJson($this->url("/{$task->id}/pause"), ['reason' => 'awaiting_feedback'])->assertStatus(422);
        $this->postJson($this->url("/{$task->id}/extensions"), $this->extension())->assertStatus(422);
    }

    public function test_a_finished_task_has_no_deadline_left_to_move(): void
    {
        $this->coordinator();
        $task = $this->task(['status' => Task::DONE, 'completed_at' => now()]);

        $this->postJson($this->url("/{$task->id}/extensions"), $this->extension())
            ->assertStatus(422)
            ->assertJsonValidationErrors('new_deadline');
    }

    /**
     * A delay recorded only on the task is a delay only the person who caused
     * it can see.
     */
    public function test_moving_a_project_deadline_raises_an_issue(): void
    {
        $this->coordinator();
        $task = $this->task();

        $this->postJson($this->url("/{$task->id}/extensions"),
            $this->extension(['responsible_party' => 'customer', 'dependency' => 'Client change board']))->assertOk();

        $issue = TaskIssue::where('task_id', $task->id)->firstOrFail();
        $this->assertStringContainsString('Deadline moved', $issue->name);
        $this->assertStringContainsString('Client change board', $issue->details);
        $this->assertStringContainsString('Customer', $issue->details);
        $this->assertSame('open', $issue->status);
    }

    /** A personal reminder that moves by a day needs no governance record. */
    public function test_a_plain_internal_task_raises_nothing(): void
    {
        $this->coordinator();
        $task = Task::create([
            'organization_id' => $this->org->id, 'category' => 'internal',
            'title' => 'Tidy my inbox', 'deadline' => now()->addDay()->toDateString(),
        ]);

        $this->postJson($this->url("/{$task->id}/extensions"), $this->extension())->assertOk();

        $this->assertSame(0, TaskIssue::where('task_id', $task->id)->count());
    }

    /** A first slip is information; a fourth is a problem with the plan. */
    public function test_repeated_slips_are_raised_more_loudly(): void
    {
        $this->coordinator();
        $task = $this->task();

        foreach ([2, 4, 6] as $weeks) {
            $this->postJson($this->url("/{$task->id}/extensions"),
                $this->extension(['new_deadline' => now()->addWeeks($weeks)->toDateString()]))->assertOk();
        }

        $issues = TaskIssue::where('task_id', $task->id)->orderBy('id')->get();
        $this->assertSame('medium', $issues[0]->priority);
        $this->assertSame('high', $issues[2]->priority);
        $this->assertStringContainsString('extension 3', $issues[2]->details);
    }

    public function test_the_delay_becomes_who_the_task_waits_on(): void
    {
        $this->coordinator();
        $task = $this->task();

        $this->postJson($this->url("/{$task->id}/extensions"),
            $this->extension(['responsible_party' => 'vendor']))->assertOk();

        $this->assertSame('vendor', $task->fresh()->responsibility);
    }

    // ── Pausing ───────────────────────────────────────────────────────────

    public function test_pausing_stops_the_clock_and_says_who_on(): void
    {
        $this->coordinator();
        $task = $this->task();

        $res = $this->postJson($this->url("/{$task->id}/pause"), [
            'reason' => 'awaiting_signoff',
            'note' => 'Sent to Milton on Friday.',
            'expected_resume_on' => now()->addWeek()->toDateString(),
        ])->assertOk()
          ->assertJsonPath('data.is_paused', true)
          ->assertJsonPath('data.pause.reason', 'awaiting_signoff')
          ->assertJsonPath('data.pause.category', 'pending_customer');

        // The family decides the party unless the caller overrides it, and
        // pausing is itself the answer to "who are we waiting on".
        $this->assertSame('customer', $res->json('data.pause.responsible_party'));
        $this->assertSame('customer', $task->fresh()->responsibility);

        // Status is untouched — the column it sits in is a separate question.
        $this->assertSame('todo', $task->fresh()->status);
    }

    public function test_pausing_raises_an_issue_naming_the_wait(): void
    {
        $this->coordinator();
        $task = $this->task();

        $this->postJson($this->url("/{$task->id}/pause"), [
            'reason' => 'awaiting_delivery', 'expected_resume_on' => now()->addWeek()->toDateString(),
        ])->assertOk();

        $issue = TaskIssue::where('task_id', $task->id)->firstOrFail();
        $this->assertStringContainsString('Awaiting delivery', $issue->name);
        $this->assertStringContainsString('Vendor', $issue->details);
    }

    public function test_a_task_pauses_once_at_a_time(): void
    {
        $this->coordinator();
        $task = $this->task();

        $this->postJson($this->url("/{$task->id}/pause"), ['reason' => 'awaiting_feedback'])->assertOk();
        $this->postJson($this->url("/{$task->id}/pause"), ['reason' => 'awaiting_payment'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_only_a_reason_the_system_knows(): void
    {
        $this->coordinator();
        $task = $this->task();

        $this->postJson($this->url("/{$task->id}/pause"), ['reason' => 'awaiting_the_weather'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_resuming_closes_the_wait_and_the_issue_it_raised(): void
    {
        $this->coordinator();
        $task = $this->task();

        $this->postJson($this->url("/{$task->id}/pause"), ['reason' => 'awaiting_approval'])->assertOk();
        $this->postJson($this->url("/{$task->id}/resume"), ['note' => 'Signed on Tuesday.'])
            ->assertOk()
            ->assertJsonPath('data.is_paused', false)
            ->assertJsonPath('data.pause', null);

        $pause = TaskPause::where('task_id', $task->id)->firstOrFail();
        $this->assertNotNull($pause->resumed_at);
        $this->assertSame('Signed on Tuesday.', $pause->resume_note);

        // The wait is over, so the task is ours again.
        $this->assertSame('self', $task->fresh()->responsibility);
        $this->assertSame('resolved', TaskIssue::where('task_id', $task->id)->value('status'));
    }

    public function test_resuming_a_task_that_never_paused_says_so(): void
    {
        $this->coordinator();
        $task = $this->task();

        $this->postJson($this->url("/{$task->id}/resume"))->assertStatus(422);
    }

    /**
     * The number that turns "eleven days late" into "eleven days late, nine of
     * them theirs".
     */
    public function test_the_history_adds_up_the_time_somebody_else_held_it(): void
    {
        $this->coordinator();
        $task = $this->task();

        $this->postJson($this->url("/{$task->id}/pause"), ['reason' => 'awaiting_information'])->assertOk();
        // Backdate the wait so there is something to measure.
        TaskPause::where('task_id', $task->id)->update(['paused_at' => now()->subDays(4)]);
        $this->postJson($this->url("/{$task->id}/resume"))->assertOk();

        $data = $this->getJson($this->url("/{$task->id}"))->assertOk()->json('data');
        $this->assertEqualsWithDelta(4.0, $data['paused_days'], 0.1);
        $this->assertCount(1, $data['pause_history']);
        $this->assertNotNull($data['pause_history'][0]['resumed_at']);
    }

    /** Past the date it was promised back — what an escalation keys off. */
    public function test_a_wait_that_runs_past_its_promised_date_says_so(): void
    {
        $this->coordinator();
        $task = $this->task();

        $this->postJson($this->url("/{$task->id}/pause"), [
            'reason' => 'awaiting_licensing', 'expected_resume_on' => now()->addDay()->toDateString(),
        ])->assertOk();

        $this->assertFalse($this->getJson($this->url("/{$task->id}"))->json('data.pause.is_overdue'));

        TaskPause::where('task_id', $task->id)->update(['expected_resume_on' => now()->subDays(3)->toDateString()]);

        $this->assertTrue($this->getJson($this->url("/{$task->id}"))->json('data.pause.is_overdue'));
    }

    public function test_a_resume_date_cannot_be_in_the_past(): void
    {
        $this->coordinator();
        $task = $this->task();

        $this->postJson($this->url("/{$task->id}/pause"), [
            'reason' => 'awaiting_site_works', 'expected_resume_on' => now()->subWeek()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('expected_resume_on');
    }
}
