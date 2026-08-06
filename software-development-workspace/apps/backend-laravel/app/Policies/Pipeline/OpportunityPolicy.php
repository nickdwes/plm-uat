<?php

namespace App\Policies\Pipeline;

use App\Models\Pipeline\Opportunity;
use App\Models\User;
use App\Policies\BasePolicy;
use App\Support\Authorization\Permission;

class OpportunityPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canAny($user, Permission::PIPELINE_VIEW, Permission::PIPELINE_VIEW_OWN);
    }

    public function view(User $user, Opportunity $opportunity): bool
    {
        if (! $this->inCurrentOrg($opportunity)) {
            return false;
        }

        if ($this->can($user, Permission::PIPELINE_VIEW)) {
            return true;
        }

        return $this->can($user, Permission::PIPELINE_VIEW_OWN) && $this->isTheirs($user, $opportunity);
    }

    public function create(User $user): bool
    {
        return $this->can($user, Permission::PIPELINE_CREATE);
    }

    public function update(User $user, Opportunity $opportunity): bool
    {
        return $this->canTouch($user, Permission::PIPELINE_UPDATE, $opportunity);
    }

    public function delete(User $user, Opportunity $opportunity): bool
    {
        return $this->canTouch($user, Permission::PIPELINE_DELETE, $opportunity);
    }

    /**
     * Whether this person may see the money on a deal.
     *
     * Not `canTouch`: reading a figure is not editing a record, and someone
     * with read-only commercial access is exactly who this is for — finance
     * reviewing the pipeline without being able to change it.
     */
    public function viewCommercials(User $user, Opportunity $opportunity): bool
    {
        return $this->inCurrentOrg($opportunity)
            && $this->can($user, Permission::PIPELINE_VIEW_COMMERCIALS);
    }

    /**
     * Moving a deal into Win or Lost.
     *
     * Held apart from `update` because the won figure is what gets reported
     * upwards. Correcting a deal name and booking it as won should not be the
     * same authority.
     */
    public function close(User $user, Opportunity $opportunity): bool
    {
        return $this->canTouch($user, Permission::PIPELINE_CLOSE, $opportunity);
    }

    private function isTheirs(User $user, Opportunity $opportunity): bool
    {
        $employeeId = $user->employeeId();

        if ($employeeId === null) {
            return false;
        }

        return $opportunity->owner_id === $employeeId
            || $opportunity->presale_lead_id === $employeeId
            || $opportunity->assignees()->where('employee_id', $employeeId)->exists();
    }
}
