<?php

namespace App\Policies;

use App\Models\User;

class EmployeePolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isManager($user);
    }

    public function view(User $user, mixed $employee): bool
    {
        // Employees can view their own record; managers and HR can view any.
        return $this->isManager($user)
            || (isset($employee->user_id) && $user->id === $employee->user_id);
    }

    public function create(User $user): bool
    {
        return $this->isCompanyHR($user);
    }

    public function update(User $user, mixed $employee): bool
    {
        return $this->isCompanyHR($user);
    }

    public function delete(User $user, mixed $employee): bool
    {
        return $this->isGlobalHR($user);
    }
}
