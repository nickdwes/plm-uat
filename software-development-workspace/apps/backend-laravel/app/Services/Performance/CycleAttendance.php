<?php

namespace App\Services\Performance;

use App\Models\Attendance\AttendanceDay;
use App\Models\PerformanceCycle;
use Illuminate\Support\Carbon;

/**
 * How present somebody was over a review cycle.
 *
 * Advisory: it is shown beside the rating for a reviewer to weigh, and it does
 * not compute any part of the rating. That is deliberate — an attendance
 * record people dispute should not turn into an appraisal people dispute, and
 * a rating that is partly computed stops being a judgement somebody is
 * accountable for.
 *
 * ── What counts as expected ──
 *
 * Weekends, public holidays and approved leave are removed from the
 * denominator. Somebody on signed-off annual leave is not absent in any sense
 * a review should weigh, and leaving those days in would mean the more leave
 * you are entitled to, the worse you look.
 *
 * ── What counts as absent ──
 *
 * Only `absent`, which is the one status that asserts somebody did not come.
 * `no_clock_in` is a recording gap, not an absence: there is no evidence
 * either way, and counting it against somebody accuses them of not turning up
 * on the strength of missing data. It is reported separately so the gap is
 * visible rather than silently swallowed — a reviewer can then go and ask.
 *
 * `no_clock_out` is somebody who came and forgot to close the day. They were
 * there.
 */
class CycleAttendance
{
    /** They were there. */
    private const PRESENT = ['on_time', 'late', 'early', 'no_clock_out'];

    /** The only status that says somebody did not come. */
    private const ABSENT = ['absent'];

    /** Neither established. Excluded from the rate and named. */
    private const UNRECORDED = ['no_clock_in'];

    /** Not a working day for this person. */
    private const NOT_EXPECTED = ['weekend', 'holiday', 'on_leave'];

    public function for(int $employeeId, PerformanceCycle $cycle, ?Carbon $today = null): array
    {
        [$from, $to] = $cycle->windowAsAt($today);

        /*
         * Nothing has happened yet. A cycle that has not started has no
         * attendance — not nought per cent, which would read as never turning
         * up.
         */
        if ($to->lt($from)) {
            return $this->nothing($cycle, $from, $to, 'This cycle has not started yet.');
        }

        $counts = AttendanceDay::query()
            ->where('employee_id', $employeeId)
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('status, count(*) as days')
            ->groupBy('status')
            ->pluck('days', 'status');

        $sum = fn (array $statuses) => collect($statuses)->sum(fn ($s) => (int) ($counts[$s] ?? 0));

        $present    = $sum(self::PRESENT);
        $absent     = $sum(self::ABSENT);
        $unrecorded = $sum(self::UNRECORDED);
        $onLeave    = (int) ($counts['on_leave'] ?? 0);

        // Days established one way or the other. The unrecorded are excluded
        // from both halves rather than assumed.
        $expected = $present + $absent;

        if ($expected === 0) {
            return $this->nothing(
                $cycle, $from, $to,
                $unrecorded > 0
                    ? "No day in this cycle was recorded either way — {$unrecorded} are missing a clock-in."
                    : 'No attendance was recorded for this person in this cycle.',
            ) + ['on_leave' => $onLeave, 'unrecorded' => $unrecorded];
        }

        return [
            'from'        => $from->toDateString(),
            'to'          => $to->toDateString(),
            'cycle'       => $cycle->name,
            'present'     => $present,
            'absent'      => $absent,
            'expected'    => $expected,
            // Carries its denominator, always. "92%" on its own is a number
            // somebody repeats without the 118 days it was 92% of.
            'rate'        => round(($present / $expected) * 100, 1),
            'late'        => (int) ($counts['late'] ?? 0),
            'on_leave'    => $onLeave,
            'unrecorded'  => $unrecorded,
            'note'        => null,
        ];
    }

    /**
     * No rate, rather than a rate of nought.
     *
     * An empty set has no attendance rate. Printing 0% would say somebody
     * never turned up, and printing 100% would say they never missed a day;
     * both are claims the data does not support.
     */
    private function nothing(PerformanceCycle $cycle, Carbon $from, Carbon $to, string $why): array
    {
        return [
            'from'       => $from->toDateString(),
            'to'         => $to->toDateString(),
            'cycle'      => $cycle->name,
            'present'    => 0,
            'absent'     => 0,
            'expected'   => 0,
            'rate'       => null,
            'late'       => 0,
            'on_leave'   => 0,
            'unrecorded' => 0,
            'note'       => $why,
        ];
    }
}
