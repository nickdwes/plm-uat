<?php

namespace Tests\Feature\Attendance;

use App\Models\Attendance\AttendanceCommitment;
use App\Models\Attendance\AttendanceDay;
use App\Models\Attendance\AttendancePolicy;
use App\Models\Employee;
use App\Models\Notification;
use App\Models\Organization;
use App\Models\User;
use App\Services\Attendance\DayCloseReminder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Asking people to close their day, and telling somebody when they do not.
 *
 * The commitment record makes communication possible; it does not make it
 * happen. Without this the unexplained count grows quietly until a manager
 * thinks to look — the same failure as before with better bookkeeping, because
 * the person who could have explained it in ten seconds was never asked.
 */
class DayCloseReminderTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);

        // Home time at five, which the reminder reads rather than assuming.
        AttendancePolicy::forOrganization($this->org->id)->update(['work_ends_at' => '17:00']);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function employee(string $first = 'Test', ?int $reportsTo = null, bool $withLogin = true): Employee
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

    private function day(Employee $employee, string $date, ?string $status = null): AttendanceDay
    {
        return AttendanceDay::create([
            'organization_id' => $this->org->id,
            'employee_id'     => $employee->id,
            'work_date'       => $date,
            'status'          => $status,
        ]);
    }

    private function commitment(AttendanceDay $day, array $attrs = []): AttendanceCommitment
    {
        return $day->commitments()->create($attrs + [
            'organization_id' => $this->org->id,
            'title'           => 'Send the proposal',
        ]);
    }

    private function reminder(): DayCloseReminder
    {
        return app(DayCloseReminder::class);
    }

    /** Five in the afternoon, on the day itself. */
    private function homeTime(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date . ' 17:30');
    }

    // ── The nudge ─────────────────────────────────────────────────────────

    /**
     * Being asked once, on the day, while they still remember why, is the whole
     * intervention.
     */
    public function test_somebody_with_open_commitments_is_asked_to_close_their_day(): void
    {
        $employee = $this->employee();
        $day = $this->day($employee, now()->toDateString());
        $this->commitment($day);

        $this->reminder()->remind($this->org->id, $this->homeTime(now()->toDateString()));

        $notification = Notification::where('user_id', $employee->user_id)->firstOrFail();
        $this->assertSame('attendance.day_not_closed', $notification->type);
    }

    /** Nothing to ask about while the day is still running. */
    public function test_nobody_is_nudged_before_the_end_of_the_working_day(): void
    {
        $employee = $this->employee();
        $this->commitment($this->day($employee, now()->toDateString()));

        $this->reminder()->remind($this->org->id, CarbonImmutable::parse(now()->toDateString() . ' 11:00'));

        $this->assertSame(0, Notification::count());
    }

    public function test_a_day_that_was_closed_off_is_left_alone(): void
    {
        $employee = $this->employee();
        $day = $this->day($employee, now()->toDateString());
        $this->commitment($day, ['outcome' => 'done']);

        $this->reminder()->remind($this->org->id, $this->homeTime(now()->toDateString()));

        $this->assertSame(0, Notification::count());
    }

    /** Somebody who promised nothing is a different problem, not this one. */
    public function test_a_day_with_no_commitments_is_not_nudged(): void
    {
        $employee = $this->employee();
        $this->day($employee, now()->toDateString());

        $this->reminder()->remind($this->org->id, $this->homeTime(now()->toDateString()));

        $this->assertSame(0, Notification::count());
    }

    /** Once. An hourly sweep that asks every hour is one people mute. */
    public function test_the_nudge_is_sent_only_once(): void
    {
        $employee = $this->employee();
        $this->commitment($this->day($employee, now()->toDateString()));

        $this->reminder()->remind($this->org->id, $this->homeTime(now()->toDateString()));
        $this->reminder()->remind($this->org->id, $this->homeTime(now()->toDateString()));

        $this->assertSame(1, Notification::count());
    }

    /**
     * Nobody owes an explanation for a day they were not expected to work, and
     * asking would teach people the whole thing is noise.
     */
    public function test_weekends_holidays_and_leave_are_never_nudged(): void
    {
        foreach (['weekend', 'holiday', 'on_leave'] as $status) {
            $employee = $this->employee('P' . $status);
            $this->commitment($this->day($employee, now()->toDateString(), $status));
        }

        $this->reminder()->remind($this->org->id, $this->homeTime(now()->toDateString()));

        $this->assertSame(0, Notification::count());
    }

    /**
     * An employee with no login cannot be reached, and the sweep must not keep
     * retrying them every hour for ever.
     */
    public function test_somebody_without_a_login_is_marked_as_handled(): void
    {
        $employee = $this->employee('Contractor', withLogin: false);
        $day = $this->day($employee, now()->toDateString());
        $this->commitment($day);

        $this->reminder()->remind($this->org->id, $this->homeTime(now()->toDateString()));

        $this->assertSame(0, Notification::count());
        $this->assertNotNull($day->fresh()->reminded_at);
    }

    // ── The escalation ────────────────────────────────────────────────────

    /**
     * The morning after, not the same evening. A manager told at five o'clock
     * that something is not finished is being told about the ordinary state of
     * ordinary work.
     */
    public function test_a_day_nobody_closed_is_raised_with_the_manager(): void
    {
        $manager  = $this->employee('Manager');
        $employee = $this->employee('Away', reportsTo: $manager->id);

        $this->commitment($this->day($employee, now()->subDay()->toDateString()));

        $this->reminder()->escalate($this->org->id);

        $notification = Notification::where('user_id', $manager->user_id)->firstOrFail();
        $this->assertSame('attendance.day_unexplained', $notification->type);
    }

    public function test_today_is_never_escalated(): void
    {
        $manager  = $this->employee('Manager');
        $employee = $this->employee('Away', reportsTo: $manager->id);

        $this->commitment($this->day($employee, now()->toDateString()));

        $this->reminder()->escalate($this->org->id);

        $this->assertSame(0, Notification::count());
    }

    public function test_a_day_that_was_answered_is_never_escalated(): void
    {
        $manager  = $this->employee('Manager');
        $employee = $this->employee('Away', reportsTo: $manager->id);

        $day = $this->day($employee, now()->subDay()->toDateString());
        $this->commitment($day, ['outcome' => 'not_done', 'reason' => 'Blocked on the client.']);

        $this->reminder()->escalate($this->org->id);

        $this->assertSame(0, Notification::count());
    }

    public function test_the_escalation_is_sent_only_once(): void
    {
        $manager  = $this->employee('Manager');
        $employee = $this->employee('Away', reportsTo: $manager->id);

        $this->commitment($this->day($employee, now()->subDay()->toDateString()));

        $this->reminder()->escalate($this->org->id);
        $this->reminder()->escalate($this->org->id);

        $this->assertSame(1, Notification::count());
    }

    /**
     * A module switched on today should not open by telling every manager
     * about every day since the epoch.
     */
    public function test_days_older_than_a_fortnight_are_left_alone(): void
    {
        $manager  = $this->employee('Manager');
        $employee = $this->employee('Away', reportsTo: $manager->id);

        $this->commitment($this->day($employee, now()->subMonths(2)->toDateString()));

        $this->reminder()->escalate($this->org->id);

        $this->assertSame(0, Notification::count());
    }

    public function test_somebody_with_no_manager_escalates_to_nobody_and_is_not_retried(): void
    {
        $employee = $this->employee('Nobody above them');
        $day = $this->day($employee, now()->subDay()->toDateString());
        $this->commitment($day);

        $this->reminder()->escalate($this->org->id);

        $this->assertSame(0, Notification::count());
        $this->assertNotNull($day->fresh()->escalated_at);
    }

    // ── The command ───────────────────────────────────────────────────────

    /** Safe to run as often as you like — both stages stamp what they touch. */
    public function test_the_command_runs_over_every_organisation_and_repeats_nothing(): void
    {
        $manager  = $this->employee('Manager');
        $employee = $this->employee('Away', reportsTo: $manager->id);

        $this->commitment($this->day($employee, now()->subDay()->toDateString()));

        $this->artisan('attendance:remind-open-commitments')->assertSuccessful();
        $this->artisan('attendance:remind-open-commitments')->assertSuccessful();

        $this->assertSame(1, Notification::count());
    }
}
