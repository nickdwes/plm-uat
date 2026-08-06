<?php

namespace App\Policies\Pipeline;

use App\Models\Pipeline\Research;
use App\Models\User;
use App\Policies\BasePolicy;
use App\Support\Authorization\Permission;

/**
 * Authorization for research.
 *
 * Gated on the pipeline permissions rather than a set of its own. Research is
 * work done to decide whether a deal is possible — the people who work the
 * pipeline are the people who commission and read it, and a fourth permission
 * family would be one more thing nobody assigns.
 */
class ResearchPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canAny($user, Permission::PIPELINE_VIEW, Permission::PIPELINE_VIEW_OWN);
    }

    public function view(User $user, Research $research): bool
    {
        if (! $this->inCurrentOrg($research)) {
            return false;
        }

        if ($this->can($user, Permission::PIPELINE_VIEW)) {
            return true;
        }

        return $this->can($user, Permission::PIPELINE_VIEW_OWN) && $this->isTheirs($user, $research);
    }

    public function create(User $user): bool
    {
        return $this->can($user, Permission::PIPELINE_CREATE);
    }

    public function update(User $user, Research $research): bool
    {
        return $this->canTouch($user, Permission::PIPELINE_UPDATE, $research);
    }

    public function delete(User $user, Research $research): bool
    {
        return $this->canTouch($user, Permission::PIPELINE_DELETE, $research);
    }

    /**
     * Whether they lead it or are on the team.
     *
     * The team is included for the same reason it is on a lead: being asked to
     * research something you cannot open would be a strange arrangement.
     */
    private function isTheirs(User $user, Research $research): bool
    {
        $employeeId = $user->employeeId();

        if ($employeeId === null) {
            return false;
        }

        return $research->research_lead_id === $employeeId
            || $research->team()->where('employee_id', $employeeId)->exists();
    }
}
