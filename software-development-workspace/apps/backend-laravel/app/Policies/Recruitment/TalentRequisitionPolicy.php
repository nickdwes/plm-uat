<?php

namespace App\Policies\Recruitment;

use App\Models\Recruitment\TalentRequisition;
use App\Models\User;
use App\Policies\BasePolicy;
use App\Support\Authorization\Permission;

/**
 * Who may see, raise and decide requisitions.
 *
 * Deciding is deliberately not a permission. Whether you can approve a
 * particular requisition depends on whether the chain named you for it, which
 * a role cannot answer — a permission that let anyone with `manage` approve
 * would make the chain decorative.
 */
class TalentRequisitionPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canAny($user, Permission::REQUISITIONS_VIEW, Permission::REQUISITIONS_CREATE);
    }

    public function view(User $user, TalentRequisition $requisition): bool
    {
        if (! $this->inCurrentOrg($requisition)) {
            return false;
        }

        if ($this->can($user, Permission::REQUISITIONS_VIEW)) {
            return true;
        }

        return $this->isInvolved($user, $requisition);
    }

    public function create(User $user): bool
    {
        return $this->can($user, Permission::REQUISITIONS_CREATE);
    }

    /**
     * Editing.
     *
     * The requester may change their own while it is still theirs to change.
     * Once it is in front of somebody, editing it would mean approving one
     * thing and advertising another.
     */
    public function update(User $user, TalentRequisition $requisition): bool
    {
        if (! $this->inCurrentOrg($requisition)) {
            return false;
        }

        if ($this->can($user, Permission::REQUISITIONS_MANAGE)) {
            return true;
        }

        return $requisition->requested_by === $user->employeeId()
            && in_array($requisition->status, ['draft', 'on_hold', 'declined'], true);
    }

    public function delete(User $user, TalentRequisition $requisition): bool
    {
        return $this->canTouch($user, Permission::REQUISITIONS_MANAGE, $requisition);
    }

    /** They asked for it, or they are somewhere in its chain. */
    private function isInvolved(User $user, TalentRequisition $requisition): bool
    {
        $employeeId = $user->employeeId();

        if ($employeeId === null) {
            return false;
        }

        return $requisition->requested_by === $employeeId
            || $requisition->approvals()->where('employee_id', $employeeId)->exists();
    }
}
