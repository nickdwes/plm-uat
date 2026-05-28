<?php

namespace App\Policies\Recruitment;

use App\Models\User;
use App\Policies\BasePolicy;
use App\Models\Recruitment\Vacancy;

class VacancyPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isManager($user);
    }

    public function view(User $user, Vacancy $vacancy): bool
    {
        return $this->isManager($user);
    }

    public function create(User $user): bool
    {
        return $this->isCompanyHR($user);
    }

    public function update(User $user, Vacancy $vacancy): bool
    {
        return $this->isCompanyHR($user);
    }

    public function delete(User $user, Vacancy $vacancy): bool
    {
        return $this->isCompanyHR($user);
    }
}
