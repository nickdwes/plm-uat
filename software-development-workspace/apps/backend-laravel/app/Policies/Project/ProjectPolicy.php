<?php

namespace App\Policies\Project;

use App\Models\Project\Project;
use App\Models\User;
use App\Policies\BasePolicy;
use App\Support\Authorization\Permission;

/**
 * Authorization for projects.
 *
 * Uses the `projects.*` permissions that have been in the catalogue since it
 * was written and never had anything behind them — not the pipeline's. A deal
 * and its delivery are worked by different people, and the whole point of a
 * kickoff is the handover.
 */
class ProjectPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canAny($user, Permission::PROJECTS_VIEW, Permission::PROJECTS_VIEW_OWN);
    }

    public function view(User $user, Project $project): bool
    {
        if (! $this->inCurrentOrg($project)) {
            return false;
        }

        if ($this->can($user, Permission::PROJECTS_VIEW)) {
            return true;
        }

        return $this->can($user, Permission::PROJECTS_VIEW_OWN) && $this->isTheirs($user, $project);
    }

    public function create(User $user): bool
    {
        return $this->can($user, Permission::PROJECTS_CREATE);
    }

    public function update(User $user, Project $project): bool
    {
        return $this->canTouch($user, Permission::PROJECTS_UPDATE, $project);
    }

    public function delete(User $user, Project $project): bool
    {
        return $this->canTouch($user, Permission::PROJECTS_DELETE, $project);
    }

    /** Putting somebody on a project, which is a staffing decision. */
    public function assign(User $user, Project $project): bool
    {
        return $this->canTouch($user, Permission::PROJECTS_ASSIGN, $project);
    }

    private function isTheirs(User $user, Project $project): bool
    {
        $employeeId = $user->employeeId();

        if ($employeeId === null) {
            return false;
        }

        return $project->project_lead_id === $employeeId
            || $project->team()->where('employee_id', $employeeId)->exists();
    }
}
