<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeaveRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The figures along the top of the leave screen.
 *
 * Each is counted over exactly the population the table below it lists, so a
 * team leader's "20 on leave today" is their twenty and an HR officer's is the
 * organisation's. A headline that counts a different set from the rows
 * underneath is the kind of number people quote in a meeting and cannot then
 * reconcile.
 */
class LeaveSummary
{
    /**
     * @param  Collection<int, int>|null  $employeeIds  Null means every employee in the organisation.
     */
    public function for(int $organizationId, ?Collection $employeeIds, ?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::now())->startOfDay();

        $base = fn () => LeaveRequest::withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds));

        $pending = (clone $base())->where('status', 'pending')->count();

        /*
         * Away today: approved, and today falls inside the request. Pending
         * requests are excluded — somebody who has asked for today off but has
         * not been granted it is expected at work.
         */
        $onLeaveToday = (clone $base())
            ->where('status', 'approved')
            ->whereDate('from_date', '<=', $today->toDateString())
            ->whereDate('to_date', '>=', $today->toDateString())
            ->distinct('employee_id')
            ->count('employee_id');

        /*
         * The next seven days, not "the rest of this week". A Friday reading
         * of a week that ends on Sunday is a two-day forecast, which is not
         * what anybody planning cover is asking for.
         */
        $upcoming = (clone $base())
            ->where('status', 'approved')
            ->whereDate('from_date', '>', $today->toDateString())
            ->whereDate('from_date', '<=', $today->copy()->addDays(7)->toDateString())
            ->count();

        $rejectedThisMonth = (clone $base())
            ->where('status', 'rejected')
            ->whereDate('actioned_at', '>=', $today->copy()->startOfMonth()->toDateString())
            ->whereDate('actioned_at', '<=', $today->copy()->endOfMonth()->toDateString())
            ->count();

        return [
            'pending_requests'    => $pending,
            'on_leave_today'      => $onLeaveToday,
            'upcoming_this_week'  => $upcoming,
            'rejected_this_month' => $rejectedThisMonth,
            'availability'        => $this->availability($organizationId, $employeeIds, $onLeaveToday),
        ];
    }

    /**
     * How much of the population is at work today, as a percentage.
     *
     * Carries its denominator, and is null when there is nobody to count.
     * A team of nobody is not 100% available and is not 0% available — it has
     * no availability, and printing either number invents a fact.
     */
    private function availability(int $organizationId, ?Collection $employeeIds, int $away): array
    {
        $headcount = Employee::query()
            ->where('status', 'active')
            ->when(
                $employeeIds !== null,
                fn ($q) => $q->whereIn('id', $employeeIds),
                fn ($q) => $q->forOrganization($organizationId),
            )
            ->count();

        return [
            'available'  => max(0, $headcount - $away),
            'headcount'  => $headcount,
            'percentage' => $headcount > 0
                ? round((($headcount - $away) / $headcount) * 100, 1)
                : null,
        ];
    }
}
