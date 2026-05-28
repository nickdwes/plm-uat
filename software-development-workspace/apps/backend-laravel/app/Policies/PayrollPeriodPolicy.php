<?php

namespace App\Policies;

use App\Models\PayrollPeriod;
use App\Models\User;

class PayrollPeriodPolicy extends BasePolicy
{
    /** HR can list payroll periods; employees can see their own history via the history endpoint. */
    public function viewAny(User $user): bool
    {
        return $this->isCompanyHR($user);
    }

    /** HR and employees (for pay-slip access) can view a period. */
    public function view(User $user): bool
    {
        return $this->isEmployee($user);
    }

    /** Only HR can create a new payroll period. */
    public function create(User $user): bool
    {
        return $this->isCompanyHR($user);
    }

    /** HR can trigger payroll processing. */
    public function process(User $user): bool
    {
        return $this->isCompanyHR($user);
    }

    /** Only Group Admin / Global HR can approve payroll. */
    public function approve(User $user): bool
    {
        return $this->isGlobalHR($user);
    }
}
