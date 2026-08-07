<?php

namespace App\Services\Attendance;

use App\Models\Attendance\AttendanceAudit;
use App\Models\Attendance\AttendanceDay;
use App\Models\Attendance\AttendancePolicy;
use App\Models\Attendance\AttendancePunch;
use App\Models\LeaveRequest;
use App\Models\PublicHoliday;
use Carbon\CarbonImmutable;

/**
 * Deciding what a day was.
 *
 * Everything else in the module reads this — the calendar cells, the three
 * summary cards, the report. So the rules live here once rather than being
 * re-derived by each screen that needs them, which is how three views of the
 * same Thursday end up disagreeing.
 *
 * The rule that shapes the rest: a day is not absent until it is over. A
 * Thursday afternoon with nobody clocked in is a Thursday afternoon, and
 * marking it absent at 09:00 would put a red square on the calendar of somebody
 * sitting at their desk.
 */
class AttendanceService
{
    /**
     * The day record, made if it is not there yet.
     *
     * Days are created lazily. Pre-creating a row per employee per day would
     * mean tens of thousands of empty rows a year and a nightly job that must
     * never miss, in exchange for nothing — a day nobody has touched is fully
     * described by the fact that it is missing.
     */
    public function dayFor(int $organizationId, int $employeeId, string $date, ?int $userId = null): AttendanceDay
    {
        return AttendanceDay::firstOrCreate(
            ['organization_id' => $organizationId, 'employee_id' => $employeeId, 'work_date' => $date],
            ['created_by' => $userId],
        );
    }

    /**
     * Start the clock.
     *
     * Clocking in twice is the same clock-in. People press the button again
     * when a page has not refreshed, and the alternative is a day with two
     * open punches and no honest way to total it.
     */
    public function clockIn(
        AttendanceDay $day,
        ?string $locationType = null,
        ?int $userId = null,
    ): AttendancePunch {
        $open = $day->punches()->whereNull('clocked_out_at')->first();

        if ($open) {
            return $open;
        }

        $punch = $day->punches()->create([
            'organization_id' => $day->organization_id,
            'clocked_in_at'   => now(),
            'location_type'   => $locationType ?? $day->planned_location,
            'created_by'      => $userId,
        ]);

        /*
         * Clocking in states where you are, if nobody said in advance. The
         * requirement allows reporting on the day as well as ahead of it, and
         * making somebody say it twice is how the field stops being filled in.
         */
        if ($day->actual_location === null) {
            $day->actual_location = $locationType ?? $day->planned_location;
        }

        $this->recalculate($day);

        return $punch;
    }

    /** Close the open punch. Nothing to close is not an error, just nothing. */
    public function clockOut(AttendanceDay $day): ?AttendancePunch
    {
        $open = $day->punches()->whereNull('clocked_out_at')->latest('clocked_in_at')->first();

        if ($open === null) {
            return null;
        }

        $open->update(['clocked_out_at' => now()]);
        $this->recalculate($day->fresh('punches'));

        return $open->fresh();
    }

    /**
     * Hours entered after the fact — the designs' "Add Hours" tab.
     *
     * Marked as manual rather than made to look like a clock reading. Both are
     * legitimate; conflating them means a report cannot tell what was measured
     * from what was remembered.
     */
    public function addHours(
        AttendanceDay $day,
        string $from,
        string $to,
        ?string $note = null,
        ?int $userId = null,
    ): AttendancePunch {
        $punch = $day->punches()->create([
            'organization_id' => $day->organization_id,
            'clocked_in_at'   => $from,
            'clocked_out_at'  => $to,
            'location_type'   => $day->actual_location ?? $day->planned_location,
            'is_manual'       => true,
            'note'            => $note,
            'created_by'      => $userId,
        ]);

        $this->recalculate($day->fresh('punches'));

        return $punch;
    }

    /**
     * Roll the punches up and decide the status.
     *
     * The status is stored rather than computed on read — the one place in this
     * system where that is the right way round. It depends on the policy as it
     * stood on the day, and changing the office start time next year must not
     * retroactively make last year's punctual staff late.
     */
    public function recalculate(AttendanceDay $day): AttendanceDay
    {
        $policy  = AttendancePolicy::forOrganization($day->organization_id);
        $punches = $day->punches()->get();

        $day->first_clock_in_at = $punches->min('clocked_in_at');
        $day->last_clock_out_at = $punches->whereNotNull('clocked_out_at')->max('clocked_out_at');

        // Summed per punch, so a lunch break between two of them is not paid
        // for by counting first-in to last-out.
        $worked = $punches->sum(fn (AttendancePunch $p) => $p->minutes ?? 0);
        $day->worked_minutes = $worked ?: null;

        $standardMinutes = (int) round((float) $policy->standard_hours * 60);
        $over = $worked - $standardMinutes;
        $day->overtime_minutes = $over > $policy->overtime_after_minutes ? $over : null;

        $day->status = $this->statusFor($day, $policy, $punches->count() > 0);
        $day->save();

        return $day;
    }

    /**
     * What the day was.
     *
     * Ordered deliberately: the reasons somebody was legitimately not at work
     * are checked before anything that could look like a failure, so a public
     * holiday is never reported as an absence.
     */
    private function statusFor(AttendanceDay $day, AttendancePolicy $policy, bool $hasPunches): ?string
    {
        $date = CarbonImmutable::parse($day->work_date);

        if (! $policy->worksOn($date)) {
            return 'weekend';
        }

        if ($this->isHoliday($day->organization_id, $date)) {
            return 'holiday';
        }

        if ($this->isOnLeave($day->organization_id, $day->employee_id, $date)) {
            return 'on_leave';
        }

        if (! $hasPunches) {
            /*
             * Not absent until the day is over. A Thursday afternoon with
             * nobody clocked in is a Thursday afternoon, and a red square on
             * the calendar of somebody sitting at their desk is how people
             * learn to distrust the whole page.
             */
            return $date->isFuture() || $date->isToday() ? null : 'absent';
        }

        if ($day->first_clock_in_at === null) {
            return 'no_clock_in';
        }

        // Clocked in and never out, on a day that has finished. A different
        // failure from not turning up, and counted separately.
        if ($day->last_clock_out_at === null && $date->isPast()) {
            return 'no_clock_out';
        }

        $expected = $date->setTimeFromTimeString($policy->work_starts_at);
        $arrived  = CarbonImmutable::parse($day->first_clock_in_at);

        if ($arrived->greaterThan($expected->addMinutes($policy->grace_minutes))) {
            return 'late';
        }

        // Meaningfully early rather than merely punctual — the designs count
        // "early clock in" apart from "on time", so it needs its own threshold
        // or every keen arrival would be indistinguishable from a normal one.
        if ($arrived->lessThan($expected->subMinutes(30))) {
            return 'early';
        }

        return 'on_time';
    }

    private function isHoliday(int $organizationId, CarbonImmutable $date): bool
    {
        return PublicHoliday::where('organization_id', $organizationId)
            ->whereDate('date', $date->toDateString())
            ->exists();
    }

    private function isOnLeave(int $organizationId, int $employeeId, CarbonImmutable $date): bool
    {
        return LeaveRequest::where('organization_id', $organizationId)
            ->where('employee_id', $employeeId)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->whereDate('from_date', '<=', $date->toDateString())
            ->whereDate('to_date', '>=', $date->toDateString())
            ->exists();
    }

    /**
     * Record a change to a day.
     *
     * Called with the values before and after, rather than reading the model,
     * because by the time anything interesting has happened the model already
     * holds the new value and the old one is gone.
     */
    public function audit(AttendanceDay $day, array $before, ?int $userId): void
    {
        foreach ($before as $field => $was) {
            $now = $day->getAttribute($field);

            if ((string) $was === (string) $now) {
                continue;
            }

            AttendanceAudit::create([
                'organization_id'   => $day->organization_id,
                'attendance_day_id' => $day->id,
                'field'      => $field,
                'from_value' => $was === null ? null : (string) $was,
                'to_value'   => $now === null ? null : (string) $now,
                'changed_by' => $userId,
                'changed_at' => now(),
            ]);
        }
    }
}
