<?php

namespace App\Services\Leave;

use App\Models\LeaveType;
use App\Models\PublicHoliday;
use Carbon\Carbon;

/**
 * Calculates the number of leave days consumed by a request, taking into account:
 *
 *  - counting_method: 'business_days' (excludes weekends + public holidays) or
 *                     'calendar_days' (counts every calendar day).
 *  - working_days:    which ISO weekdays (1=Mon…7=Sun) are work days for this type.
 *                     Defaults to Mon–Fri [1,2,3,4,5].
 *  - Half-day:        always = 0.5, regardless of method.
 *  - Scattered dates: sum of each individual date contribution.
 *  - Consecutive:     iterate every date from from_date to to_date.
 *
 * Public holidays for the org are queried once per call and cached locally.
 */
class LeaveCalculatorService
{
    /** @var array<string, bool>  map of 'YYYY-MM-DD' => true for active PH dates */
    private array $holidayCache = [];
    private ?int  $cachedOrgId  = null;

    // ── Public API ────────────────────────────────────────────────────────

    /**
     * Calculate the total leave days for a request.
     *
     * @param  LeaveType   $leaveType
     * @param  int         $orgId
     * @param  string|null $fromDate        Required for consecutive mode.
     * @param  string|null $toDate          Required for consecutive mode.
     * @param  bool        $isHalfDay
     * @param  string[]|null $scatteredDates Array of 'YYYY-MM-DD' strings.
     * @return float
     */
    public function calculate(
        LeaveType $leaveType,
        int $orgId,
        ?string $fromDate,
        ?string $toDate,
        bool $isHalfDay = false,
        ?array $scatteredDates = null,
    ): float {
        if ($isHalfDay) {
            return 0.5;
        }

        $this->primeCache($orgId);

        if (! empty($scatteredDates)) {
            $total = 0.0;
            foreach ($scatteredDates as $date) {
                $total += $this->countDay($leaveType, $date);
            }
            return $total;
        }

        if (! $fromDate || ! $toDate) {
            return 0.0;
        }

        $total   = 0.0;
        $current = Carbon::parse($fromDate)->startOfDay();
        $end     = Carbon::parse($toDate)->startOfDay();

        while ($current->lte($end)) {
            $total += $this->countDay($leaveType, $current->toDateString());
            $current->addDay();
        }

        return $total;
    }

    /**
     * Return a detailed breakdown for UI preview.
     *
     * @return array<int, array{date: string, weekday: string, counted: bool, reason: string}>
     */
    public function breakdown(
        LeaveType $leaveType,
        int $orgId,
        ?string $fromDate,
        ?string $toDate,
        bool $isHalfDay = false,
        ?array $scatteredDates = null,
    ): array {
        if ($isHalfDay) {
            return [[
                'date'    => $fromDate ?? '',
                'weekday' => $fromDate ? Carbon::parse($fromDate)->format('D') : '',
                'counted' => true,
                'reason'  => 'Half day',
            ]];
        }

        $this->primeCache($orgId);
        $workingDays = $this->resolveWorkingDays($leaveType);
        $dates = [];

        if (! empty($scatteredDates)) {
            $dates = $scatteredDates;
        } elseif ($fromDate && $toDate) {
            $current = Carbon::parse($fromDate)->startOfDay();
            $end     = Carbon::parse($toDate)->startOfDay();
            while ($current->lte($end)) {
                $dates[] = $current->toDateString();
                $current->addDay();
            }
        }

        $result = [];
        foreach ($dates as $date) {
            $carbon  = Carbon::parse($date);
            $iso     = $carbon->isoWeekday(); // 1=Mon, 7=Sun
            $counted = false;
            $reason  = '';

            if ($leaveType->counting_method === 'calendar_days') {
                $counted = true;
                $reason  = 'Calendar day';
            } elseif (! in_array($iso, $workingDays)) {
                $counted = false;
                $reason  = $carbon->format('l') . ' (not a working day)';
            } elseif (isset($this->holidayCache[$date])) {
                $counted = false;
                $reason  = 'Public holiday';
            } else {
                $counted = true;
                $reason  = 'Working day';
            }

            $result[] = [
                'date'    => $date,
                'weekday' => $carbon->format('D'),
                'counted' => $counted,
                'reason'  => $reason,
            ];
        }

        return $result;
    }

    // ── Private helpers ───────────────────────────────────────────────────

    private function countDay(LeaveType $leaveType, string $date): float
    {
        if ($leaveType->counting_method === 'calendar_days') {
            return 1.0;
        }

        // Business days: check working day and public holidays.
        $workingDays = $this->resolveWorkingDays($leaveType);
        $iso         = Carbon::parse($date)->isoWeekday(); // 1=Mon, 7=Sun

        if (! in_array($iso, $workingDays)) {
            return 0.0;
        }

        if (isset($this->holidayCache[$date])) {
            return 0.0;
        }

        return 1.0;
    }

    /**
     * Returns the configured working days array (ISO weekday numbers).
     * Falls back to Mon–Fri [1,2,3,4,5] when not set.
     *
     * @return int[]
     */
    private function resolveWorkingDays(LeaveType $leaveType): array
    {
        $wd = $leaveType->working_days;
        if (! empty($wd) && is_array($wd)) {
            return array_map('intval', $wd);
        }
        return [1, 2, 3, 4, 5]; // Mon–Fri default
    }

    /**
     * Load all active public holidays for the org into a fast lookup map.
     * Only queries the DB once per org per service instance.
     */
    private function primeCache(int $orgId): void
    {
        if ($this->cachedOrgId === $orgId) {
            return;
        }

        $this->holidayCache = [];
        $this->cachedOrgId  = $orgId;

        $dates = PublicHoliday::where('organization_id', $orgId)
            ->where('is_active', true)
            ->pluck('date')
            ->toArray();

        foreach ($dates as $d) {
            // Normalise to YYYY-MM-DD string.
            $this->holidayCache[(string) $d] = true;
        }
    }
}
