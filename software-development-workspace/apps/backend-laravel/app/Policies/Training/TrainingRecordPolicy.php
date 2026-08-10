<?php

namespace App\Policies\Training;

use App\Models\Employee;
use App\Models\Training\EmployeeTrainingRecord;
use App\Models\User;
use App\Policies\BasePolicy;
use App\Support\Authorization\Permission;

/**
 * What a particular person has been trained in.
 *
 * A record of somebody failing a certification twice is a performance fact
 * about them, not catalogue data — so reading one is scoped the way employee
 * records are: your own, your reports', or everybody's with the permission.
 */
class TrainingRecordPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canAny($user, Permission::TRAINING_VIEW, Permission::TRAINING_VIEW_OWN)
            || $this->isEmployee($user);
    }

    public function view(User $user, EmployeeTrainingRecord $record): bool
    {
        if ($this->can($user, Permission::TRAINING_VIEW) || $this->isCompanyHR($user)) {
            return true;
        }

        $me = $user->employeeId();

        if ($me !== null && $record->employee_id === $me) {
            return true;
        }

        // A manager sees what their own reports have been trained in.
        return $me !== null
            && Employee::whereKey($record->employee_id)->where('reports_to', $me)->exists();
    }

    public function create(User $user): bool
    {
        return $this->canAny($user, Permission::TRAINING_MANAGE, Permission::TRAINING_APPROVE)
            || $this->isCompanyHR($user);
    }

    public function update(User $user, EmployeeTrainingRecord $record): bool
    {
        return $this->can($user, Permission::TRAINING_MANAGE) || $this->isCompanyHR($user);
    }

    public function delete(User $user, EmployeeTrainingRecord $record): bool
    {
        return $this->update($user, $record);
    }
}
