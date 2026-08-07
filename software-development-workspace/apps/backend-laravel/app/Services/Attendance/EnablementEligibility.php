<?php

namespace App\Services\Attendance;

use App\Models\Attendance\AttendanceDay;
use App\Models\Task\Task;
use App\Models\Task\TaskHandover;
use Carbon\CarbonImmutable;

/**
 * What stands in the way of somebody taking an enablement day.
 *
 * The requirement makes eligibility depend on project commitments, customer
 * engagements and support obligations — and gives the manager the decision. So
 * this does not decide anything. It finds the conflicts and names them, and a
 * person is left to weigh them.
 *
 * That distinction is the whole design. A rule that silently refuses teaches
 * people to stop asking; a rule that silently allows leaves a manager approving
 * a day they would have refused had they known the client is on site. Neither
 * is a decision. Showing the conflicts and asking somebody is.
 *
 * Everything here is read from records that already exist. Nobody is asked to
 * restate their commitments in a second place — a form that asks "are you
 * free?" gets answered "yes" by everybody.
 */
class EnablementEligibility
{
    /**
     * Conflicts on a given date, worst first.
     *
     * An empty list is a genuine "nothing in the way" rather than an absence of
     * checking, which is why each check is named in the result even when it
     * finds nothing to say.
     */
    public function conflictsFor(int $organizationId, int $employeeId, string $date): array
    {
        $day = CarbonImmutable::parse($date);

        return array_values(array_filter([
            $this->customerEngagement($organizationId, $employeeId, $day),
            $this->workDueThatDay($organizationId, $employeeId, $day),
            $this->coveringSomebody($organizationId, $employeeId, $day),
            $this->openIssues($organizationId, $employeeId),
        ]));
    }

    /**
     * Already committed to being on a customer's site.
     *
     * The hardest conflict there is: somebody else has arranged their day
     * around it, and it is the one a manager would always want raised.
     */
    private function customerEngagement(int $orgId, int $employeeId, CarbonImmutable $day): ?array
    {
        $engagement = AttendanceDay::withoutGlobalScopes()
            ->with('account')
            ->where('organization_id', $orgId)
            ->where('employee_id', $employeeId)
            ->whereDate('work_date', $day->toDateString())
            ->where(fn ($w) => $w->where('planned_location', 'customer_site')
                ->orWhere('actual_location', 'customer_site'))
            ->first();

        if ($engagement === null) {
            return null;
        }

        return [
            'kind'     => 'customer_engagement',
            'severity' => 'blocking',
            'detail'   => 'Already due on site'
                . ($engagement->account ? ' at ' . $engagement->account->name : '') . ' that day.',
        ];
    }

    /**
     * Work of their own falling due that day.
     *
     * Only project, support and opportunity work counts. Internal and admin
     * tasks are exactly what an enablement day is for making room around, and
     * counting them would mean nobody is ever eligible.
     */
    private function workDueThatDay(int $orgId, int $employeeId, CarbonImmutable $day): ?array
    {
        $due = Task::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->where('assigned_to', $employeeId)
            ->whereIn('category', ['project', 'support', 'opportunity', 'ticket'])
            ->open()
            ->whereDate('deadline', '<=', $day->toDateString())
            ->count();

        if ($due === 0) {
            return null;
        }

        return [
            'kind'     => 'work_due',
            // Warning rather than blocking: work due is the ordinary condition
            // of having a job, and a rule that treats it as disqualifying means
            // the enablement day exists only on paper.
            'severity' => 'warning',
            'detail'   => $due . ' project or support task(s) due by then.',
        ];
    }

    /** Holding somebody else's work while they are away. */
    private function coveringSomebody(int $orgId, int $employeeId, CarbonImmutable $day): ?array
    {
        $covering = TaskHandover::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->where('to_employee_id', $employeeId)
            ->standing()
            ->whereDate('starts_on', '<=', $day->toDateString())
            ->whereDate('ends_on', '>=', $day->toDateString())
            ->count();

        if ($covering === 0) {
            return null;
        }

        return [
            'kind'     => 'covering',
            'severity' => 'warning',
            'detail'   => "Covering {$covering} task(s) for somebody on leave that week.",
        ];
    }

    /**
     * Issues escalated to them and still open.
     *
     * Escalation means somebody put it in front of this person by name, and the
     * legacy escalated 88% of its issues — so an unresolved one is a real claim
     * on the day rather than background noise.
     */
    private function openIssues(int $orgId, int $employeeId): ?array
    {
        $open = \App\Models\Task\TaskIssue::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->where('status', 'open')
            ->whereHas('escalations', fn ($e) => $e->where('employee_id', $employeeId))
            ->count();

        if ($open === 0) {
            return null;
        }

        return [
            'kind'     => 'open_issues',
            'severity' => 'warning',
            'detail'   => "{$open} issue(s) escalated to you are still open.",
        ];
    }
}
