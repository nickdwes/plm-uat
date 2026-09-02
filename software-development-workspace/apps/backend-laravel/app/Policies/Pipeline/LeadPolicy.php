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
        return $this->canAny($user, Permission::PIPELINE_VIEW, Permission::PIPELINE_VIEW_TEAM, Permission::PIPELINE_VIEW_OWN);
    }

    public function view(User $user, Lead $lead): bool
    {
        if (! $this->inCurrentOrg($lead)) {
            return false;
        }

        if ($this->can($user, Permission::PIPELINE_VIEW)) {
            return true;
        }

        // The team width: my leads plus my direct reports' (one level, the
        // house convention). The list narrows itself the same way, so the
        // record a manager can open is exactly one their list can show.
        if ($this->can($user, Permission::PIPELINE_VIEW_TEAM) && $this->isTheirTeams($user, $lead)) {
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
     * Committing money against a lead that may never close.
     *
     * Whoever works a lead may ask for a car, a flight or lunch for the room;
     * saying yes is a different authority, so it hangs off `pipeline.close`
     * rather than `pipeline.update`. Deliberately **not** `canTouch` — owning
     * the lead is precisely what should not let you approve your own spend on
     * it.
     */
    public function close(User $user, Lead $lead): bool
    {
        return $this->inCurrentOrg($lead) && $this->can($user, Permission::PIPELINE_CLOSE);
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

    private function isTheirTeams(User $user, Lead $lead): bool
    {
        $team = \App\Support\Authorization\ResourceAttributes::teamEmployeeIds($user);

        if ($team === []) {
            return false;
        }

        return in_array((int) $lead->owner_id, $team, true)
            || $lead->team()->whereIn('employee_id', $team)->exists();
    }
}
