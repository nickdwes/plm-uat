<?php

namespace App\Policies\Pipeline;

use App\Models\Pipeline\Lead;
use App\Models\User;
use App\Policies\BasePolicy;
use App\Support\Authorization\Permission;

/**
 * Authorization for leads.
 *
 * Same distinction the customer master draws: seeing the pipeline is not the
 * same as seeing your own. Somebody holding only `view_own` sees the leads
 * they own or research, which is what a salesperson needs and is short of what
 * the whole book would tell them about colleagues' deals.
 */
class LeadPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canAny($user, Permission::PIPELINE_VIEW, Permission::PIPELINE_VIEW_OWN);
    }

    public function view(User $user, Lead $lead): bool
    {
        if (! $this->inCurrentOrg($lead)) {
            return false;
        }

        if ($this->can($user, Permission::PIPELINE_VIEW)) {
            return true;
        }

        return $this->can($user, Permission::PIPELINE_VIEW_OWN) && $this->isTheirs($user, $lead);
    }

    public function create(User $user): bool
    {
        return $this->can($user, Permission::PIPELINE_CREATE);
    }

    public function update(User $user, Lead $lead): bool
    {
        return $this->canTouch($user, Permission::PIPELINE_UPDATE, $lead);
    }

    public function delete(User $user, Lead $lead): bool
    {
        return $this->canTouch($user, Permission::PIPELINE_DELETE, $lead);
    }

    /**
     * Whether they own it or are on the research team.
     *
     * The team is included because a lead's detail screen shows a research
     * team and expects them to work on it — being asked to research something
     * you cannot open would be a strange arrangement.
     */
    private function isTheirs(User $user, Lead $lead): bool
    {
        $employeeId = $user->employeeId();

        if ($employeeId === null) {
            return false;
        }

        return $lead->owner_id === $employeeId
            || $lead->team()->where('employee_id', $employeeId)->exists();
    }
}
