<?php

namespace App\Policies;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\Authorization\Permission;

/**
 * Who may see and act on a leave request.
 *
 * More sensitive than it looks. The reason attached to a request is often
 * medical, sometimes a bereavement, occasionally a domestic matter somebody has
 * told their manager and nobody else. It read as ordinary HR admin, so it was
 * gated as ordinary HR admin — any manager could open any request in the
 * organisation.
 *
 * The catalogue already carried `leave.view`, `leave.view_own`,
 * `leave.view_team`, `leave.approve` and the rest, and none of them were used.
 */
class LeaveRequestPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canAny(
            $user,
            Permission::LEAVE_VIEW,
            Permission::LEAVE_VIEW_TEAM,
            Permission::LEAVE_VIEW_OWN,
        ) || $this->isEmployee($user);
    }

    /**
     * Reading one.
     *
     * Their own always; their manager's own reports with the team scope;
     * anybody's only with the full permission. A manager reading a peer's
     * bereavement leave is the case this closes.
     */
    public function view(User $user, LeaveRequest $leaveRequest): bool
    {
        if ($this->can($user, Permission::LEAVE_VIEW) || $this->isCompanyHR($user)) {
            return true;
        }

        $me = $user->employeeId();

        if ($me !== null && $leaveRequest->employee_id === $me) {
            return true;
        }

        // The approver on the request, whoever they are, and a manager over
        // the person who asked.
        if ($me !== null && $leaveRequest->approved_by === $me) {
            return true;
        }

        return $this->can($user, Permission::LEAVE_VIEW_TEAM)
            && $me !== null
            && \App\Models\Employee::whereKey($leaveRequest->employee_id)
                ->where('reports_to', $me)->exists();
    }

    public function create(User $user): bool
    {
        return $this->can($user, Permission::LEAVE_SUBMIT) || $this->isEmployee($user);
    }

    /** Approving or rejecting. */
    public function action(User $user): bool
    {
        return $this->canAny($user, Permission::LEAVE_APPROVE, Permission::LEAVE_REJECT, Permission::LEAVE_MANAGE)
            || $this->isManager($user);
    }
}
