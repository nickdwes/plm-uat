<?php

namespace App\Policies;

use App\Models\LeaveRequest;
use App\Models\User;

class LeaveRequestPolicy extends BasePolicy
{
    /** Any authenticated employee can list leave requests (own) or manager/HR can see all. */
    public function viewAny(User $user): bool
    {
        return $this->isEmployee($user);
    }

    /** Employees can view their own; managers/HR can view any in the org. */
    public function view(User $user, LeaveRequest $leaveRequest): bool
    {
        return $this->isManager($user)
            || $leaveRequest->employee?->user_id === $user->id;
    }

    /** Any employee with a linked employee record can submit a leave request. */
    public function create(User $user): bool
    {
        return $this->isEmployee($user);
    }

    /** Approve or reject requires manager/HR role. */
    public function action(User $user): bool
    {
        return $this->isManager($user);
    }
}
