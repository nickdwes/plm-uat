<?php

namespace App\Models\Support;

use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * When the desk is open.
 *
 * The old clock was raw datetime arithmetic: a ticket raised at 5pm on Friday
 * burned a four-hour target before anybody was at a desk. Every calculation
 * here is in working minutes against this calendar.
 *
 * A day with no window is a day nobody is at a desk, which is how 24×7 and 8×5
 * differ without a flag to get wrong — 24×7 is seven rows of midnight to
 * midnight.
 */
class Calendar extends Model
{
    use BelongsToOrganization;

    protected $table = 'support_calendars';

    protected $fillable = ['organization_id', 'name', 'timezone', 'is_default'];

    protected $casts = ['is_default' => 'boolean'];

    protected $attributes = ['timezone' => 'Africa/Nairobi', 'is_default' => false];

    public function windows(): HasMany
    {
        return $this->hasMany(CalendarWindow::class, 'support_calendar_id');
    }

    public function holidays(): HasMany
    {
        return $this->hasMany(CalendarHoliday::class, 'support_calendar_id');
    }

    // ── Working time ──────────────────────────────────────────────────────────

    /**
     * Working minutes between two instants.
     *
     * Walks day by day and intersects each day's window with the interval.
     * Deliberately not clever: an interval spanning a year is 365 cheap
     * comparisons, and clever arithmetic here is how off-by-one-day bugs get
     * into an SLA report.
     */
    public function workingMinutesBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        if ($to->lessThanOrEqualTo($from)) {
            return 0;
        }

        $tz    = $this->timezone;
        $start = $from->copy()->setTimezone($tz);
        $end   = $to->copy()->setTimezone($tz);

        $minutes = 0;
        $day     = $start->copy()->startOfDay();

        while ($day->lessThanOrEqualTo($end)) {
            foreach ($this->windowsOn($day) as [$opens, $closes]) {
                $slotStart = $opens->greaterThan($start) ? $opens : $start;
                $slotEnd   = $closes->lessThan($end) ? $closes : $end;

                if ($slotEnd->greaterThan($slotStart)) {
                    $minutes += $slotStart->diffInMinutes($slotEnd);
                }
            }

            $day->addDay();
        }

        return (int) round($minutes);
    }

    /**
     * When a promise of N working minutes falls due, in real time.
     *
     * Answering in real time is the point: a due date is what a countdown on a
     * queue is drawn from and what an escalation is triggered by, and neither
     * can be done in working minutes without recomputing the calendar on every
     * render.
     */
    public function addWorkingMinutes(CarbonInterface $from, int $minutes): CarbonInterface
    {
        $tz        = $this->timezone;
        $cursor    = $from->copy()->setTimezone($tz);
        $remaining = $minutes;

        if ($remaining <= 0) {
            return $cursor->copy();
        }

        // A year of days is a generous bound; a calendar with no open days at
        // all would otherwise loop forever, and that is a configuration people
        // do create by accident.
        for ($guard = 0; $guard < 400 && $remaining > 0; $guard++) {
            $day = $cursor->copy()->startOfDay()->addDays($guard);

            foreach ($this->windowsOn($day) as [$opens, $closes]) {
                $slotStart = $opens->greaterThan($cursor) ? $opens : $cursor;

                if ($closes->lessThanOrEqualTo($slotStart)) {
                    continue;
                }

                $available = $slotStart->diffInMinutes($closes);

                if ($available >= $remaining) {
                    return $slotStart->copy()->addMinutes($remaining);
                }

                $remaining -= $available;
            }
        }

        /*
         * Nothing open within the horizon. Returning the start rather than null
         * keeps callers simple, and a due date equal to the open date reads on
         * screen as obviously wrong — which is better than a silent null that
         * disables the countdown and looks fine.
         */
        return $cursor->copy();
    }

    /** Whether the desk is open at a given instant. */
    public function isOpenAt(CarbonInterface $at): bool
    {
        $at = $at->copy()->setTimezone($this->timezone);

        foreach ($this->windowsOn($at->copy()->startOfDay()) as [$opens, $closes]) {
            if ($at->greaterThanOrEqualTo($opens) && $at->lessThan($closes)) {
                return true;
            }
        }

        return false;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /** @return array<array{0: CarbonInterface, 1: CarbonInterface}> */
    private function windowsOn(CarbonInterface $day): array
    {
        $date = $day->copy()->setTimezone($this->timezone)->startOfDay();

        if ($this->holidayDates()->contains($date->toDateString())) {
            return [];
        }

        $window = $this->windowsByWeekday()->get($date->dayOfWeek);

        if (! $window) {
            return [];
        }

        $opens  = $date->copy()->setTimeFromTimeString($window->opens_at);
        $closes = $date->copy()->setTimeFromTimeString($window->closes_at);

        // Midnight-to-midnight is stored as 00:00–00:00 and means the whole day.
        if ($closes->lessThanOrEqualTo($opens)) {
            $closes = $date->copy()->addDay();
        }

        return [[$opens, $closes]];
    }

    private ?\Illuminate\Support\Collection $windowCache = null;
    private ?\Illuminate\Support\Collection $holidayCache = null;

    private function windowsByWeekday(): \Illuminate\Support\Collection
    {
        return $this->windowCache ??= $this->windows()->get()->keyBy('weekday');
    }

    private function holidayDates(): \Illuminate\Support\Collection
    {
        return $this->holidayCache ??= $this->holidays()->pluck('observed_on')
            ->map(fn ($d) => \Carbon\Carbon::parse($d)->toDateString());
    }
}
