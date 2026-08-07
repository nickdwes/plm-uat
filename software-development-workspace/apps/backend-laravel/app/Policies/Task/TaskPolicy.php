<?php

namespace App\Policies\Task;

use App\Models\Task\Task;
use App\Models\User;
use App\Policies\BasePolicy;
use App\Support\Authorization\Permission;

/**
 * Authorization for tasks.
 *
 * Uses `tasks.*`, which has been in the catalogue unused since it was written.
 * A task is not a project artefact — three quarters of the legacy's had no
 * project — so raising one must not require project permissions.
 */
class TaskPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canAny($user, Permission::TASKS_VIEW, Permission::TASKS_VIEW_OWN);
    }

    public function view(User $user, Task $task): bool
    {
        if (! $this->inCurrentOrg($task)) {
            return false;
        }

        if ($this->can($user, Permission::TASKS_VIEW)) {
            return true;
        }

        return $this->can($user, Permission::TASKS_VIEW_OWN) && $this->isTheirs($user, $task);
    }

    public function create(User $user): bool
    {
        return $this->can($user, Permission::TASKS_CREATE);
    }

    /**
     * Editing a task.
     *
     * Whoever it is assigned to can edit it whatever their permission scope —
     * a task you are doing and cannot update is a task that goes stale, and the
     * legacy's 261 unanswered delegations are what that looks like at scale.
     */
    public function update(User $user, Task $task): bool
    {
        if (! $this->inCurrentOrg($task)) {
            return false;
        }

        if ($this->can($user, Permission::TASKS_UPDATE)) {
            return true;
        }

        return $this->can($user, Permission::TASKS_VIEW_OWN) && $this->isTheirs($user, $task);
    }

    public function delete(User $user, Task $task): bool
    {
        return $this->canTouch($user, Permission::TASKS_DELETE, $task);
    }

    /**
     * Assigned to them, helping with it, asked about it, or signing it off.
     *
     * The approver belongs here for the same reason as the rest: somebody who
     * cannot open a task cannot act on it, and a task waiting on an approver
     * who cannot see it waits forever.
     */
    private function isTheirs(User $user, Task $task): bool
    {
        $employeeId = $user->employeeId();

        if ($employeeId === null) {
            return false;
        }

        return $task->assigned_to === $employeeId
            || $task->approver_id === $employeeId
            || $task->members()->where('employee_id', $employeeId)->exists()
            || $task->requests()->where('to_employee_id', $employeeId)->exists();
    }
}
