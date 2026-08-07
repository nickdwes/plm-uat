<?php

namespace Tests\Feature\Attendance;

use App\Models\Attendance\AttendanceCommitment;
use App\Models\Attendance\AttendancePolicy;
use App\Models\Employee;
use App\Models\Notification;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Task\Task;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What somebody committed to for a day, and how it went.
 *
 * Not a timesheet. The module asks three things: what did you say you would
 * finish today, did you finish it, and if not, why not — and the third is the
 * one enforced, because it is the only one people skip and the only one anybody
 * else needs.
 *
 * The number that matters is not how much slipped. Work slips, and a manager
 * reacting to that is reacting to noise. It is how much slipped with nobody
 * told, which had no way of being counted before.
 */
class DayCommitmentTest extends TestCase
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

    private function employee(string $first = 'Test', ?int $reportsTo = null, bool $withLogin = false): Employee
    {
        $employee = Employee::create([
            'employee_number'         => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id,
            'first_name' => $first, 'last_name' => 'Person',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
            'reports_to' => $reportsTo,
        ]);

        if ($withLogin) {
            $employee->update(['user_id' => User::factory()->create()->id]);
        }

        return $employee->fresh();
    }

    private function staff(string $first = 'Test', ?int $reportsTo = null): Employee
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

        $employee = $this->employee($first, $reportsTo);
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

    private function commit(array $body, ?string $date = null): \Illuminate\Testing\TestResponse
    {
        return $this->postJson($this->url('/commitments'), $body + ['work_date' => $date ?? $this->today()]);
    }

    private function report(int $id, array $body): \Illuminate\Testing\TestResponse
    {
        return $this->postJson($this->url("/commitments/{$id}/report"), $body);
    }

    // ── Committing to work ────────────────────────────────────────────────

    public function test_a_day_is_a_list_of_what_you_said_you_would_finish(): void
    {
        $this->staff();
        $task = $this->task();

        $this->commit(['task_id' => $task->id])
            ->assertOk()
            ->assertJsonPath('data.commitments.0.title', 'Redesign messaging component library')
            ->assertJsonPath('data.commitments.0.task.id', $task->id)
            // Nobody has said how it went yet, which is not the same as it
            // having gone well.
            ->assertJsonPath('data.commitments.0.outcome', null)
            ->assertJsonPath('data.tally.promised', 1);
    }

    /**
     * Committing does not create work. If it did, the task list would fill with
     * a second copy of everything anybody ever planned.
     */
    public function test_committing_to_a_day_creates_no_tasks(): void
    {
        $this->staff();
        $task = $this->task();

        $this->commit(['task_id' => $task->id])->assertOk();
        $this->commit(['title' => 'Call the client back'])->assertOk();

        $this->assertSame(1, Task::count());
    }

    /** "Call the client back" is a real commitment nobody wants as a task. */
    public function test_a_commitment_need_not_be_a_task(): void
    {
        $this->staff();

        $this->commit(['title' => 'Call the client back'])
            ->assertOk()
            ->assertJsonPath('data.commitments.0.title', 'Call the client back')
            ->assertJsonPath('data.commitments.0.task', null);
    }

    /**
     * Times exist for people who want them and nothing is built on them. A grid
     * you must fill in is a timesheet, and timesheets get completed on Friday
     * from memory.
     */
    public function test_a_commitment_needs_no_times(): void
    {
        $this->staff();

        $this->commit(['title' => 'Finish the migration plan'])
            ->assertOk()
            ->assertJsonPath('data.commitments.0.starts_at', null)
            ->assertJsonPath('data.commitments.0.ends_at', null);
    }

    public function test_a_time_can_still_be_noted_where_it_matters(): void
    {
        $this->staff();

        $this->commit(['title' => 'Client call', 'starts_at' => '11:00', 'ends_at' => '11:30'])
            ->assertOk()
            ->assertJsonPath('data.commitments.0.starts_at', '11:00');
    }

    // ── Saying how it went ────────────────────────────────────────────────

    public function test_finishing_something_is_recorded_as_done(): void
    {
        $this->staff();
        $this->commit(['title' => 'Finish the migration plan'])->assertOk();

        $this->report(AttendanceCommitment::firstOrFail()->id, ['outcome' => 'done'])
            ->assertOk()
            ->assertJsonPath('data.commitments.0.outcome', 'done')
            ->assertJsonPath('data.tally.done', 1)
            ->assertJsonPath('data.is_reported', true);
    }

    /**
     * The refusal is the feature. "Why not" asked on the day by the person who
     * knows is worth more than any report; asked a fortnight later it gets
     * "can't remember".
     */
    public function test_a_miss_without_a_reason_is_refused(): void
    {
        $this->staff();
        $this->commit(['title' => 'Finish the migration plan'])->assertOk();

        $this->report(AttendanceCommitment::firstOrFail()->id, ['outcome' => 'not_done'])
            ->assertJsonValidationErrorFor('reason');

        $this->assertNull(AttendanceCommitment::first()->outcome);
    }

    public function test_a_miss_with_a_reason_is_accepted_and_counted_as_explained(): void
    {
        $this->staff();
        $this->commit(['title' => 'Finish the migration plan'])->assertOk();

        $this->report(AttendanceCommitment::firstOrFail()->id, [
            'outcome' => 'not_done', 'reason' => 'The client moved the review to Thursday.',
        ])->assertOk()
            ->assertJsonPath('data.commitments.0.reason', 'The client moved the review to Thursday.')
            ->assertJsonPath('data.commitments.0.is_explained', true)
            ->assertJsonPath('data.tally.missed', 1)
            ->assertJsonPath('data.tally.unexplained', 0);
    }

    /** Changing your mind must not leave a reason describing the old answer. */
    public function test_marking_something_done_clears_an_earlier_reason(): void
    {
        $this->staff();
        $this->commit(['title' => 'Finish the migration plan'])->assertOk();
        $id = AttendanceCommitment::firstOrFail()->id;

        $this->report($id, ['outcome' => 'not_done', 'reason' => 'Ran out of day.'])->assertOk();
        $this->report($id, ['outcome' => 'done'])
            ->assertOk()
            ->assertJsonPath('data.commitments.0.reason', null);
    }

    // ── Slipping in silence ───────────────────────────────────────────────

    /**
     * The number the module exists to produce. Not how much slipped — work
     * slips — but how much slipped with nobody told.
     */
    public function test_an_unanswered_commitment_on_a_finished_day_counts_as_unexplained(): void
    {
        $this->staff();
        $yesterday = now()->subDay()->toDateString();

        $this->commit(['title' => 'Send the proposal'], $yesterday)->assertOk();

        $this->getJson($this->url('', ['from' => $yesterday, 'to' => $yesterday]))
            ->assertOk()
            ->assertJsonPath('data.0.tally.unexplained', 1)
            ->assertJsonPath('data.0.is_reported', false);
    }

    /**
     * An unanswered commitment at three in the afternoon is not a failure to
     * communicate. Counting it as one is how the figure stops being believed.
     */
    public function test_todays_unanswered_commitments_are_not_yet_unexplained(): void
    {
        $this->staff();

        $this->commit(['title' => 'Send the proposal'])
            ->assertOk()
            ->assertJsonPath('data.tally.unexplained', 0)
            ->assertJsonPath('data.tally.open', 1);
    }

    public function test_the_summary_counts_what_slipped_in_silence(): void
    {
        $this->staff();
        $yesterday = now()->subDay()->toDateString();

        $this->commit(['title' => 'Explained'], $yesterday)->assertOk();
        $this->commit(['title' => 'Silent'], $yesterday)->assertOk();
        $this->commit(['title' => 'Finished'], $yesterday)->assertOk();

        $ids = AttendanceCommitment::pluck('id', 'title');

        $this->report($ids['Explained'], ['outcome' => 'not_done', 'reason' => 'Blocked on the client.'])->assertOk();
        $this->report($ids['Finished'], ['outcome' => 'done'])->assertOk();

        $this->getJson($this->url('/summary', ['from' => $yesterday, 'to' => $yesterday]))
            ->assertOk()
            ->assertJsonPath('work.promised', 3)
            ->assertJsonPath('work.done', 1)
            ->assertJsonPath('work.missed', 1)
            // Only the one nobody said anything about.
            ->assertJsonPath('work.unexplained', 1);
    }

    // ── Telling somebody ──────────────────────────────────────────────────

    /**
     * "Effective communication if not completed" means somebody hears about it.
     * A note in a record nobody opens is the same as no note at all.
     */
    public function test_a_miss_tells_the_line_manager(): void
    {
        $manager = $this->employee('Manager', withLogin: true);
        $this->staff('Away', reportsTo: $manager->id);

        $this->commit(['title' => 'Send the proposal'])->assertOk();

        $this->report(AttendanceCommitment::firstOrFail()->id, [
            'outcome' => 'not_done', 'reason' => 'Waiting on pricing from the OEM.',
        ])->assertOk();

        $notification = Notification::where('user_id', $manager->user_id)->firstOrFail();
        $this->assertSame('attendance.commitment_missed', $notification->type);
        $this->assertSame('Waiting on pricing from the OEM.', $notification->message);
    }

    /** Whoever owns the task is the one actually held up by it. */
    public function test_a_miss_tells_whoever_owns_the_task(): void
    {
        $owner = $this->employee('Owner', withLogin: true);
        $this->staff('Helper');

        $task = $this->task(['assigned_to' => $owner->id]);
        $this->commit(['task_id' => $task->id])->assertOk();

        $this->report(AttendanceCommitment::firstOrFail()->id, [
            'outcome' => 'not_done', 'reason' => 'Ran out of day.',
        ])->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $owner->user_id, 'type' => 'attendance.commitment_missed',
        ]);
    }

    /**
     * Deliberately quiet on success. A channel that carries everything carries
     * nothing, and the miss is the only part anybody else needs.
     */
    public function test_finishing_the_work_tells_nobody(): void
    {
        $manager = $this->employee('Manager', withLogin: true);
        $this->staff('Away', reportsTo: $manager->id);

        $this->commit(['title' => 'Send the proposal'])->assertOk();
        $this->report(AttendanceCommitment::firstOrFail()->id, ['outcome' => 'done'])->assertOk();

        $this->assertSame(0, Notification::count());
    }

    // ── Carrying it over ──────────────────────────────────────────────────

    /**
     * A link to its replacement rather than a flag, so "this has slipped four
     * times" is answerable. A boolean would only say it moved once.
     */
    public function test_an_unfinished_commitment_can_be_moved_to_another_day(): void
    {
        $this->staff();
        $tomorrow = now()->addDay()->toDateString();

        $this->commit(['title' => 'Send the proposal'])->assertOk();

        $this->report(AttendanceCommitment::firstOrFail()->id, [
            'outcome' => 'not_done', 'reason' => 'Waiting on pricing.', 'carry_to' => $tomorrow,
        ])->assertOk()->assertJsonPath('data.commitments.0.carried_to.work_date', $tomorrow);

        $this->getJson($this->url('', ['from' => $tomorrow, 'to' => $tomorrow]))
            ->assertOk()
            ->assertJsonPath('data.0.commitments.0.title', 'Send the proposal')
            // The reason travels, so tomorrow's list shows why this is back
            // rather than looking like a fresh commitment.
            ->assertJsonPath('data.0.commitments.0.note', 'Waiting on pricing.');
    }

    // ── Keeping the record ────────────────────────────────────────────────

    /**
     * Deleting a reported miss would erase the explanation with it, which is
     * exactly the record this exists to keep.
     */
    public function test_something_already_reported_on_cannot_be_deleted(): void
    {
        $this->staff();
        $this->commit(['title' => 'Send the proposal'])->assertOk();
        $id = AttendanceCommitment::firstOrFail()->id;

        $this->report($id, ['outcome' => 'not_done', 'reason' => 'Blocked.'])->assertOk();

        $this->deleteJson($this->url("/commitments/{$id}"))->assertStatus(422);
        $this->assertSame(1, AttendanceCommitment::count());
    }

    public function test_something_not_yet_reported_on_can_be_dropped(): void
    {
        $this->staff();
        $this->commit(['title' => 'Changed my mind'])->assertOk();

        $this->deleteJson($this->url('/commitments/' . AttendanceCommitment::firstOrFail()->id))
            ->assertOk()
            ->assertJsonCount(0, 'data.commitments');
    }

    // ── Whose day ─────────────────────────────────────────────────────────

    public function test_committing_on_another_persons_behalf_is_refused(): void
    {
        $this->staff();
        $other = $this->employee('Other');

        $this->postJson($this->url('/commitments'), [
            'work_date' => $this->today(), 'employee_id' => $other->id,
            'title' => 'Not mine to promise',
        ])->assertForbidden();
    }

    public function test_a_task_from_another_organisation_cannot_be_committed_to(): void
    {
        $this->staff();

        $other = Organization::create(['name' => 'Beta', 'slug' => 'beta']);
        $task  = Task::withoutGlobalScopes()->create([
            'organization_id' => $other->id, 'title' => 'Somebody else’s work',
        ]);

        $this->commit(['task_id' => $task->id])->assertJsonValidationErrorFor('task_id');
    }
}
