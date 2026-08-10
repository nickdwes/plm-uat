<?php

namespace App\Policies\Recruitment;

use App\Models\Recruitment\Application;
use App\Models\User;
use App\Policies\BasePolicy;
use App\Support\Authorization\Permission;

/**
 * Who may read an application and move it along.
 *
 * Moving a stage is a decision about somebody's livelihood — it is what
 * shortlists them or ends their candidacy. It was gated on being a manager of
 * anybody, which meant a team lead with no part in the hiring could advance or
 * reject applicants for a role they had never heard of.
 */
class ApplicationPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canAny(
            $user,
            Permission::RECRUITMENT_VIEW,
            Permission::APPLICATIONS_MANAGE,
            Permission::CANDIDATES_VIEW,
        ) || $this->isCompanyHR($user);
    }

    public function view(User $user, Application $application): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canAny($user, Permission::APPLICATIONS_MANAGE, Permission::RECRUITMENT_MANAGE)
            || $this->isCompanyHR($user);
    }

    /** Shortlisting, rejecting, extending an offer. */
    public function moveStage(User $user, Application $application): bool
    {
        return $this->canAny($user, Permission::APPLICATIONS_MANAGE, Permission::RECRUITMENT_MANAGE)
            || $this->isCompanyHR($user);
    }
}
