<?php

namespace App\Services\Attendance;

use App\Models\Attendance\AttendanceDay;
use App\Models\Attendance\AttendancePolicy;
use App\Models\Employee;
use App\Services\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Asking people to close their day, and telling somebody when they do not.
 *
 * The commitment record makes communication possible; it does not make it
 * happen. Left alone, the unexplained count grows quietly until a manager
 * thinks to look — the same failure as before with better bookkeeping, because
 * the person who could have explained it in ten seconds was never asked.
 *
 * Two moments, deliberately a day apart:
 *
 *   The nudge, at the end of the person's own working day. Anybody can forget
 *   to close out; being asked once, while they still remember why, is the whole
 *   intervention.
 *
 *   The escalation, the following morning, about what stayed unanswered. Never
 *   the same evening — a manager told at five o'clock that something is not
 *   finished is being told about the ordinary state of ordinary work.
 */
class DayCloseReminder
{
    public function __construct(private NotificationService $notifications)
    {
    }

    /**
     * Nudge whoever has commitments still open on a day that has just ended.
     *
     * Scoped to one organisation because the working day is a policy, and two
     * organisations do not finish at the same time.
     */
    public function remind(int $organizationId, ?CarbonImmutable $now = null): int
    {
        $now    = $now ?? CarbonImmutable::now();
        $policy = AttendancePolicy::forOrganization($organizationId);

        // Not yet home time. Nothing to ask about.
        if ($now->format('H:i') < substr((string) $policy->work_ends_at, 0, 5)) {
            return 0;
        }

        $days = $this->openDays($organizationId, $now->toDateString())
            ->whereNull('reminded_at');

        $sent = 0;

        foreach ($days->get() as $day) {
            $open = $day->commitments->whereNull('outcome')->count();

            if ($open === 0) {
                continue;
            }

            $sent += $this->tell(
                $day->employee_id,
                $day,
                'attendance.day_not_closed',
                'Close off your day',
                $open === 1
                    ? 'One thing you committed to today is still unanswered.'
                    : "{$open} things you committed to today are still unanswered.",
            );

            // Stamped whether or not anybody could be reached, so an employee
            // with no login does not have the sweep retry them every hour.
            $day->update(['reminded_at' => now()]);
        }

        return $sent;
    }

    /**
     * Tell the manager about days that finished with commitments unanswered.
     *
     * Only about days that are over. Something still open at five o'clock is
     * work in progress; the same thing open the next morning is a question
     * nobody answered.
     */
    public function escalate(int $organizationId, ?CarbonImmutable $now = null): int
    {
        $now = $now ?? CarbonImmutable::now();

        $days = $this->openDays($organizationId, null)
            ->whereDate('work_date', '<', $now->toDateString())
            ->whereNull('escalated_at')
            // A fortnight back at most. A module switched on today should not
            // open by telling every manager about every day since the epoch.
            ->whereDate('work_date', '>=', $now->subDays(14)->toDateString());

        $sent = 0;

        foreach ($days->get() as $day) {
            $open = $day->commitments->whereNull('outcome')->count();

            if ($open === 0) {
                continue;
            }

            $employee = $day->employee;
            $managerId = $employee?->reports_to;

            if ($managerId !== null) {
                $sent += $this->tell(
                    $managerId,
                    $day,
                    'attendance.day_unexplained',
                    ($employee?->full_name ?? 'Somebody') . ' left work unexplained',
                    "{$open} commitment(s) from " . $day->work_date->format('j M')
                        . ' were never closed off.',
                );
            }

            $day->update(['escalated_at' => now()]);
        }

        return $sent;
    }

    /**
     * Days with commitments still unanswered.
     *
     * Weekends, holidays and leave are excluded: nobody owes an explanation for
     * a day they were not expected to work, and asking would teach people the
     * whole thing is noise.
     *
     * The null check is not decoration. `NOT IN` drops null rows in SQL, and a
     * day that has never been recalculated has no status — which is most of
     * them, since a status is only written when somebody clocks or reports.
     * Without this the sweep would quietly nudge nobody at all.
     */
    private function openDays(int $organizationId, ?string $date)
    {
        $q = AttendanceDay::withoutGlobalScopes()
            ->with(['employee', 'commitments'])
            ->where('organization_id', $organizationId)
            ->where(fn ($w) => $w->whereNull('status')
                ->orWhereNotIn('status', ['weekend', 'holiday', 'on_leave']))
            ->whereHas('commitments', fn ($c) => $c->whereNull('outcome'));

        return $date === null ? $q : $q->whereDate('work_date', $date);
    }

    /** Notifications reach users; days name employees. Silent where none. */
    private function tell(int $employeeId, AttendanceDay $day, string $type, string $title, string $message): int
    {
        $userId = Employee::whereKey($employeeId)->value('user_id');

        if ($userId === null) {
            return 0;
        }

        $this->notifications->create(
            (string) $day->organization_id,
            $userId,
            $type,
            $title,
            $message,
            ['attendance_day_id' => $day->id, 'work_date' => $day->work_date->toDateString()],
            AttendanceDay::class,
            (string) $day->id,
        );

        return 1;
    }

    /** Every organisation with an attendance policy, for the hourly sweep. */
    public function organizations(): Collection
    {
        return AttendancePolicy::withoutGlobalScopes()->pluck('organization_id');
    }
}
