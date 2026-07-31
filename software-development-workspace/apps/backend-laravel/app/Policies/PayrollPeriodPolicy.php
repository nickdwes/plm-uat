<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Authorization\Permission;

/**
 * Payroll authorization, resolved from permissions rather than role names.
 *
 * Which roles may approve payroll is now a configuration question answered in
 * the role administration screen, not a decision compiled into this class. Any
 * role holding `payroll.approve` can approve, in whichever tenants it is
 * assigned — which is the point of the catalogue.
 *
 * The separation of duties this class previously enforced by naming a role is
 * preserved by permission instead: `org_hr` holds `payroll.*` with
 * `payroll.approve` and `payroll.manage_statutory` excluded, so the people who
 * prepare a payroll run still cannot release it or change the rates it is
 * calculated from.
 */
class PayrollPeriodPolicy extends BasePolicy
{
    /** Listing payroll periods. */
    public function viewAny(User $user): bool
    {
        return $this->can($user, Permission::PAYROLL_VIEW);
    }

    /**
     * Viewing a period.
     *
     * Employees reach this to read their own payslip, so holding
     * `payslip.view_own` is sufficient — the slip endpoint narrows to the
     * caller's own record separately.
     */
    public function view(User $user): bool
    {
        return $this->canAny($user, Permission::PAYROLL_VIEW, Permission::PAYSLIP_VIEW_OWN);
    }

    public function create(User $user): bool
    {
        return $this->can($user, Permission::PAYROLL_CREATE);
    }

    /** Running the calculation. */
    public function process(User $user): bool
    {
        return $this->can($user, Permission::PAYROLL_PROCESS);
    }

    /**
     * Releasing a payroll run.
     *
     * Deliberately its own permission. Whoever holds this authorises money
     * leaving the business, and it should not be implied by any other payroll
     * capability.
     */
    public function approve(User $user): bool
    {
        return $this->can($user, Permission::PAYROLL_APPROVE);
    }

    /** Reading the payroll configuration screen. */
    public function viewConfig(User $user): bool
    {
        return $this->canAny(
            $user,
            Permission::PAYROLL_VIEW,
            Permission::PAYROLL_MANAGE_CONFIG,
        );
    }

    /** Earning and deduction codes — operational setup. */
    public function manageConfig(User $user): bool
    {
        return $this->can($user, Permission::PAYROLL_MANAGE_CONFIG);
    }

    /**
     * PAYE bands and statutory rates.
     *
     * updateTaxBands() replaces a whole tax year at once and these values
     * determine every payslip, so this carries the same weight as approving a
     * run rather than the same weight as editing a deduction code.
     */
    public function manageStatutory(User $user): bool
    {
        return $this->can($user, Permission::PAYROLL_MANAGE_STATUTORY);
    }
}
