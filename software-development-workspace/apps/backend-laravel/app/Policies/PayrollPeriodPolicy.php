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

    /** Reading the payroll configuration is an HR administration screen. */
    public function viewConfig(User $user): bool
    {
        return $this->isCompanyHR($user);
    }

    /**
     * Earning and deduction codes are operational payroll setup — the same
     * level of trust as creating a period.
     */
    public function manageConfig(User $user): bool
    {
        return $this->isCompanyHR($user);
    }

    /**
     * PAYE bands and statutory rates are group-level, not org-level.
     *
     * These values determine every employee's net pay, they are set by
     * legislation rather than by the business, and updateTaxBands() replaces a
     * whole tax year at once. That is the same blast radius as approving a
     * payroll run, so it takes the same authority — and it keeps the person
     * who prepares payroll separate from the person who can change what it
     * calculates.
     */
    public function manageStatutory(User $user): bool
    {
        return $this->isGlobalHR($user);
    }
}
