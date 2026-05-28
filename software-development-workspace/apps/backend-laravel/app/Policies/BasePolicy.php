<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

abstract class BasePolicy
{
    use HandlesAuthorization;

    protected function isGlobalHR(User $user): bool
    {
        return $user->isGroupAdmin();
    }

    protected function isCompanyHR(User $user): bool
    {
        return $user->isOrgHR(app('current.organization_id'));
    }

    protected function isManager(User $user): bool
    {
        return $user->isManagerIn(app('current.organization_id'));
    }

    protected function isEmployee(User $user): bool
    {
        return $user->hasRole('employee', app('current.organization_id')) || $this->isManager($user);
    }
}
