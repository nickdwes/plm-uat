<?php

namespace App\Services\HR;

use App\Models\Employee;
use App\Models\LeaveRequest;
use Illuminate\Support\Carbon;

/**
 * The figures along the top of the employee directory.
 *
 * Counted over exactly the population the table beneath them lists — employed
 * here *or placed here* — so a secondee is counted once, by the organisation
 * that hosts them, and the headline cannot disagree with the rows.
 *
 * ── On probation ──
 *
 * Counted from the status, which already accepts `probation` and which the
 * onboarding screens already write — not from `probation_end_date`. A date
 * that has passed means somebody is overdue confirmation, not that they have
 * been confirmed, and inferring the second from the first would quietly
 * promote people nobody signed off.
 *
 * `overdue_confirmation` is counted beside it because that is the figure with
 * a deadline attached: somebody has to decide.
 */
class DirectoryFigures
{
    public function for(?int $organizationId, ?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::now())->startOfDay();

        $base = fn () => Employee::query()
            ->when($organizationId, fn ($q, $org) => $q->forOrganization($org));

        /*
         * Drafts are excluded the same way the listing excludes them. A record
         * halfway through the onboarding wizard is not somebody who works
         * here, and counting it makes the headcount disagree with the table.
         */
        $counted = fn () => (clone $base())->where('status', '!=', 'draft');

        return [
            'total'       => (clone $counted())->count(),
            'active'      => (clone $counted())->where('status', 'active')->count(),
            'on_probation' => (clone $counted())->onProbation()->count(),
            'overdue_confirmation' => (clone $counted())->onProbation()
                ->whereNotNull('probation_end_date')
                ->whereDate('probation_end_date', '<', $today->toDateString())
                ->count(),
            'on_leave'    => $this->onLeave($organizationId, $today),
            'new_this_month' => (clone $counted())
                ->whereDate('date_hired', '>=', $today->copy()->startOfMonth()->toDateString())
                ->whereDate('date_hired', '<=', $today->toDateString())
                ->count(),
            'as_at'       => $today->toDateString(),
        ];
    }

    /**
     * Away today, by the same rule the leave screens use.
     *
     * Approved and covering today. Pending is excluded — somebody who has
     * asked for today off but has not been granted it is expected at work, and
     * the leave summary already counts it that way. Two screens disagreeing
     * about who is in is worse than neither showing it.
     */
    private function onLeave(?int $organizationId, Carbon $today): int
    {
        return LeaveRequest::withoutGlobalScope('organization')
            ->when($organizationId, fn ($q, $org) => $q->where('organization_id', $org))
            ->where('status', 'approved')
            ->whereDate('from_date', '<=', $today->toDateString())
            ->whereDate('to_date', '>=', $today->toDateString())
            ->distinct('employee_id')
            ->count('employee_id');
    }

    /**
     * The employee ids away today, for the "On Leave" tab.
     *
     * The tab filters the same listing rather than serving a different one, so
     * the rows under the tab are a subset of the rows without it.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    public static function onLeaveToday(?int $organizationId, ?Carbon $today = null): \Illuminate\Support\Collection
    {
        $day = ($today ?? Carbon::now())->toDateString();

        return LeaveRequest::withoutGlobalScope('organization')
            ->when($organizationId, fn ($q, $org) => $q->where('organization_id', $org))
            ->where('status', 'approved')
            ->whereDate('from_date', '<=', $day)
            ->whereDate('to_date', '>=', $day)
            ->distinct()
            ->pluck('employee_id');
    }
}
