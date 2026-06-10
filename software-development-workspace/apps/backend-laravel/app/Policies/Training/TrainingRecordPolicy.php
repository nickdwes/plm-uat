<?php

namespace App\Policies\Training;

use App\Models\Training\EmployeeTrainingRecord;
use App\Models\User;
use App\Policies\BasePolicy;

class TrainingRecordPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        // HR/Managers see all; employees see own (filtered in controller)
        return $this->isEmployee($user);
    }

    public function view(User $user, EmployeeTrainingRecord $record): bool
    {
        // Own record, or manager/HR
        if ($this->isManager($user)) {
            return true;
        }

        $employee = $user->employee;
        return $employee !== null && $employee->id === $record->employee_id;
    }

    public function create(User $user): bool
    {
        return $this->isEmployee($user);
    }

    public function update(User $user, EmployeeTrainingRecord $record): bool
    {
        // Employees update own; HR/managers update any
        if ($this->isManager($user)) {
            return true;
        }

        $employee = $user->employee;
        return $employee !== null && $employee->id === $record->employee_id;
    }

    public function delete(User $user, EmployeeTrainingRecord $record): bool
    {
        return $this->isCompanyHR($user);
    }
}
