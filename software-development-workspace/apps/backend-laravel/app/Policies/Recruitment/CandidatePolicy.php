<?php

namespace App\Policies\Recruitment;

use App\Models\Recruitment\Candidate;
use App\Models\User;
use App\Policies\BasePolicy;
use App\Support\Authorization\Permission;

/**
 * Who may read a candidate.
 *
 * These are people outside the company who sent us their CV, their phone
 * number and their employment history on the strength of a job advert. They
 * cannot see what happens to it and have no account to check.
 *
 * `candidates.view` and `recruitment.manage` were in the catalogue and unused,
 * so the gate was "is a manager of anybody" — which meant every team lead in
 * the organisation could read every applicant's personal details whether or not
 * they were involved in any hiring.
 */
class CandidatePolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canAny(
            $user,
            Permission::CANDIDATES_VIEW,
            Permission::RECRUITMENT_VIEW,
            Permission::RECRUITMENT_MANAGE,
        ) || $this->isCompanyHR($user);
    }

    public function view(User $user, Candidate $candidate): bool
    {
        return $this->inCurrentOrg($candidate) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canAny($user, Permission::RECRUITMENT_MANAGE, Permission::APPLICATIONS_MANAGE)
            || $this->isCompanyHR($user);
    }

    public function update(User $user, Candidate $candidate): bool
    {
        return $this->inCurrentOrg($candidate)
            && ($this->can($user, Permission::RECRUITMENT_MANAGE) || $this->isCompanyHR($user));
    }
}
