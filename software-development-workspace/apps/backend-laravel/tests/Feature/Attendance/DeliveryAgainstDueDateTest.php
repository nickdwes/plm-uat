<?php

namespace Tests\Feature\Attendance;

use App\Models\Attendance\AttendancePolicy;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Task\Task;
use App\Models\Task\TaskIssue;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Productivity measured as delivery against a due date, not hours against a
 * clock.
 *
 * The daily report answers three things about each piece of work: did you do
 * it, does that finish it, and if it was due before today, why is it still
 * here. Nothing asks how long anything took.
 *
 * The distinction that carries the module is closed against ongoing. Without
 * it, a task worked on every day for a fortnight is indistinguishable from one
 * abandoned after an afternoon — both are a row of "done" — and a team that is
 * making steady progress on something large reads exactly like a team that
 * finishes nothing.
 */
class DeliveryAgainstDueDateTest extends TestCase
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
            'first_name' => 'Wanjiru', 'last_name' => 'Kamau',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
            'user_id' => $user->id,
        ]);

        Sanctum::actingAs($user);

        return $employee->fresh();
    }

    private function task(array $attrs = []): Task
    {
        return Task::create($attrs + [
            'organization_id' => $this->org->id,
            'title'           => 'Migrate the reporting database',
            'status'          => 'in_progress',
        ]);
    }

    private function url(string $path = '', array $query = []): string
    {
        return "/api/v1/attendance{$path}?" . http_build_query($query + ['org' => $this->org->slug]);
    }

    private function commitTo(Task $task, ?string $date = null): int
    {
        return $this->postJson($this->url('/commitments'), [
            'work_date' => $date ?? now()->toDateString(),
            'task_id'   => $task->id,
        ])->assertSuccessful()->json('data.commitments.0.id');
    }

    private function report(int $id, array $body): \Illuminate\Testing\TestResponse
    {
        return $this->postJson($this->url("/commitments/{$id}/report"), $body);
    }

    /**
     * Open the day by saying where the work is happening.
     *
     * The first thing anybody does — "I am in the office today" — and the thing
     * that brings the day into existence. Overdue work is shown against a day,
     * so there has to be one.
     */
    private function attend(string $location = 'office'): void
    {
        $this->postJson($this->url('/record'), [
            'work_date'       => now()->toDateString(),
            'actual_location' => $location,
        ])->assertSuccessful();
    }

    // ── Closed against ongoing ────────────────────────────────────────────

    public function test_closing_a_commitment_closes_the_task(): void
    {
        $staff = $this->staff();
        $task  = $this->task(['assigned_to' => $staff->id]);

        $this->report($this->commitTo($task), ['outcome' => 'done', 'disposition' => 'closed'])
            ->assertSuccessful();

        $task->refresh();

        $this->assertSame(Task::DONE, $task->status);
        $this->assertNotNull($task->reported_done_at);
    }

    /**
     * Progress is not completion. Saying so is what stops a fortnight of steady
     * work looking like a fortnight of nothing.
     */
    public function test_an_ongoing_commitment_leaves_the_task_open(): void
    {
        $staff = $this->staff();
        $task  = $this->task(['assigned_to' => $staff->id]);

        $this->report($this->commitTo($task), ['outcome' => 'done', 'disposition' => 'ongoing'])
            ->assertSuccessful();

        $task->refresh();

        $this->assertSame('in_progress', $task->status);
        $this->assertNull($task->reported_done_at);
    }

    /** Work that needs signing off is delivered, not accepted. */
    public function test_closing_work_that_needs_approval_sends_it_for_approval(): void
    {
        $staff = $this->staff();
        $task  = $this->task(['assigned_to' => $staff->id, 'requires_approval' => true]);

        $this->report($this->commitTo($task), ['outcome' => 'done', 'disposition' => 'closed'])
            ->assertSuccessful();

        $task->refresh();

        $this->assertSame('awaiting_approval', $task->status);
        // The doer's own date is still recorded, which is the whole point:
        // otherwise a slow approval makes somebody who delivered on time late.
        $this->assertNotNull($task->reported_done_at);
        $this->assertNull($task->completed_at);
    }

    public function test_a_done_task_commitment_must_say_which(): void
    {
        $staff = $this->staff();
        $task  = $this->task(['assigned_to' => $staff->id]);

        $this->report($this->commitTo($task), ['outcome' => 'done'])
            ->assertJsonValidationErrorFor('disposition');
    }

    /**
     * A commitment with no task behind it has nothing to close, and asking is a
     * question with no consequence.
     */
    public function test_a_free_text_commitment_is_not_asked(): void
    {
        $this->staff();

        $id = $this->postJson($this->url('/commitments'), [
            'work_date' => now()->toDateString(),
            'title'     => 'Team meeting',
        ])->assertSuccessful()->json('data.commitments.0.id');

        $this->report($id, ['outcome' => 'done'])->assertSuccessful();
    }

    // ── Overdue ───────────────────────────────────────────────────────────

    /**
     * Overdue is past its date with nobody having said it is done — which is
     * not the same as past its date and unapproved.
     */
    public function test_overdue_is_measured_against_the_delivery_not_the_approval(): void
    {
        $staff = $this->staff();

        $late = $this->task([
            'assigned_to' => $staff->id,
            'deadline'    => now()->subDays(3)->toDateString(),
        ]);

        $delivered = $this->task([
            'assigned_to'      => $staff->id,
            'deadline'         => now()->subDays(3)->toDateString(),
            'reported_done_at' => now()->subDays(4),
            'status'           => 'awaiting_approval',
        ]);

        $this->assertTrue($late->isOverdue());
        // Delivered a day early and still waiting on somebody else. Not late.
        $this->assertFalse($delivered->isOverdue());
        $this->assertSame(0, $delivered->daysLate());
    }

    public function test_days_late_counts_from_the_due_date(): void
    {
        $staff = $this->staff();

        $task = $this->task([
            'assigned_to'      => $staff->id,
            'deadline'         => now()->subDays(5)->toDateString(),
            'reported_done_at' => now()->subDays(2),
        ]);

        $this->assertSame(3, $task->daysLate());
    }

    /** Never delivered is not "late by n days" — it has no answer yet. */
    public function test_undelivered_work_has_no_lateness(): void
    {
        $staff = $this->staff();

        $task = $this->task([
            'assigned_to' => $staff->id,
            'deadline'    => now()->subDays(5)->toDateString(),
        ]);

        $this->assertNull($task->daysLate());
        $this->assertTrue($task->isOverdue());
    }

    // ── The day's list ────────────────────────────────────────────────────

    /**
     * They choose their day; overdue work arrives anyway.
     *
     * A list somebody else fills in is a form rather than a plan, but the task
     * nobody volunteers for is exactly the one that slips quietly.
     */
    public function test_overdue_work_appears_on_the_day_without_being_committed_to(): void
    {
        $staff = $this->staff();

        $this->task([
            'assigned_to' => $staff->id,
            'title'       => 'Forgotten migration',
            'deadline'    => now()->subDays(2)->toDateString(),
        ]);

        $this->attend();

        $body = $this->getJson($this->url('', ['from' => now()->toDateString(), 'to' => now()->toDateString()]))
            ->assertOk()->json('data.0');

        $this->assertCount(1, $body['overdue']);
        $this->assertSame('Forgotten migration', $body['overdue'][0]['title']);
        $this->assertSame(2, $body['overdue'][0]['days_late']);
    }

    /** Committed to it already? Then it is on the list once, not twice. */
    public function test_overdue_work_already_committed_to_is_not_repeated(): void
    {
        $staff = $this->staff();

        $task = $this->task([
            'assigned_to' => $staff->id,
            'deadline'    => now()->subDays(2)->toDateString(),
        ]);

        $this->commitTo($task);

        $body = $this->getJson($this->url('', ['from' => now()->toDateString(), 'to' => now()->toDateString()]))
            ->assertOk()->json('data.0');

        $this->assertSame([], $body['overdue']);
        $this->assertTrue($body['commitments'][0]['task']['is_overdue']);
    }

    /**
     * Whether they have already said why.
     *
     * An overdue task with a risk logged against it has been accounted for, and
     * asking again is nagging rather than prompting.
     */
    public function test_the_day_says_whether_an_issue_has_been_raised(): void
    {
        $staff = $this->staff();

        $task = $this->task([
            'assigned_to' => $staff->id,
            'deadline'    => now()->subDays(2)->toDateString(),
        ]);

        $this->attend();

        $before = $this->getJson($this->url('', ['from' => now()->toDateString(), 'to' => now()->toDateString()]))
            ->assertOk()->json('data.0.overdue.0.open_issues');

        $this->assertSame(0, $before);

        TaskIssue::create([
            'organization_id' => $this->org->id,
            'task_id' => $task->id,
            'kind'    => 'risk',
            'name'    => 'Vendor has not returned the migration window',
            'status'  => 'open',
            'raised_by' => $staff->id,
        ]);

        $after = $this->getJson($this->url('', ['from' => now()->toDateString(), 'to' => now()->toDateString()]))
            ->assertOk()->json('data.0.overdue.0.open_issues');

        $this->assertSame(1, $after);
    }
}
