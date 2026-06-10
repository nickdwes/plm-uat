<?php

namespace App\Policies\Training;

use App\Models\Training\CompetencyItem;
use App\Models\Training\RoleCompetencyProfile;
use App\Models\User;
use App\Policies\BasePolicy;

class CompetencyPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isEmployee($user);
    }

    public function view(User $user, CompetencyItem|RoleCompetencyProfile $model): bool
    {
        return $this->isEmployee($user);
    }

    public function create(User $user): bool
    {
        return $this->isCompanyHR($user);
    }

    public function update(User $user, CompetencyItem|RoleCompetencyProfile $model): bool
    {
        return $this->isCompanyHR($user);
    }

    public function delete(User $user, CompetencyItem|RoleCompetencyProfile $model): bool
    {
        return $this->isCompanyHR($user);
    }
}
