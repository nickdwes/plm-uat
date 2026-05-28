<?php

namespace App\Policies\Recruitment;

use App\Models\User;
use App\Policies\BasePolicy;
use App\Models\Recruitment\Candidate;

class CandidatePolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isManager($user);
    }

    public function view(User $user, Candidate $candidate): bool
    {
        return $this->isManager($user);
    }

    public function create(User $user): bool
    {
        return $this->isManager($user);
    }

    public function update(User $user, Candidate $candidate): bool
    {
        return $this->isCompanyHR($user);
    }
}
