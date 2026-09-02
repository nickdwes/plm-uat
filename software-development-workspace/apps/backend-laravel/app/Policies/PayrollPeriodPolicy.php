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
     * Viewing payroll administration data.
     *
     * This used to admit `payslip.view_own` on the theory that "the slip
     * endpoint narrows to the caller's own record separately" — it did not:
     * getSlip() narrows by organisation only, so every base employee could
     * read any colleague's slip, the payment files and the whole P9/P10 by
     * id. Self-service payslips flow through /employees/{id}/payslips, which
     * carries its own narrowing and step-up; everything guarded HERE is the
     * payroll admin surface and needs the real permission.
     */
    public function view(User $user): bool
    {
        return $this->can($user, Permission::PAYROLL_VIEW);
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
     * Changing what is in a period: ad-hoc lines, sending its documents,
     * marking its P9 checked.
     *
     * This method did not exist, and nothing supplies a default, so every
     * caller of authorize('update', PayrollPeriod::class) denied — silently,
     * for everybody. Sending payslips has never been possible through the
     * endpoint; the delivery tests exercised the service directly and passed
     * either way.
     *
     * Mapped to PAYROLL_PROCESS rather than PAYROLL_APPROVE deliberately. The
     * money was authorised at approval; this is the preparation and
     * distribution around it, and folding it into approve would mean the only
     * person who can email a payslip is the one who signs off the run.
     */
    public function update(User $user): bool
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
