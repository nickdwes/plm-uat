<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Database\Eloquent\Model;

/**
 * Who may see and change an employee record.
 *
 * The most personal thing this system holds: national ID, KRA PIN, salary,
 * blood group, known conditions, dependants. So it is gated on the permission
 * catalogue rather than on being "a manager".
 *
 * The catalogue already carried `employees.view`, `employees.view_team`,
 * `employees.update` and the rest, and this policy ignored every one of them —
 * the same fault the vacancy policy had. It meant a custom role granted exactly
 * the right permission was refused, while anybody who managed anybody could
 * read every record in the organisation, salary and medical history included.
 *
 * The role checks remain as a fallback so that an organisation which has never
 * assigned the permissions still has its HR team able to work.
 */
class EmployeePolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canAny(
            $user,
            Permission::EMPLOYEES_VIEW,
            Permission::EMPLOYEES_VIEW_TEAM,
        ) || $this->isCompanyHR($user);
    }

    /**
     * Reading one.
     *
     * Their own record always; their manager's if the reader holds the team
     * scope; anybody's only with the full permission. A manager reaching
     * sideways into a peer's salary is the case this exists to stop.
     */
    public function view(User $user, mixed $employee): bool
    {
        // A person always sees their own record, whatever the org context.
        if (isset($employee->user_id) && $user->id === $employee->user_id) {
            return true;
        }

        // Every other branch is tenant-scoped: holding a permission in the
        // organisation currently in context must not reach a record that
        // belongs to a different one. Without this, `employees.view` in org A
        // plus another org's employee id read that employee across the
        // boundary. Group admins (no org in context) are unaffected —
        // inCurrentOrg() returns true when the context org is null.
        if (! $this->recordInCurrentOrg($employee)) {
            return false;
        }

        if ($this->can($user, Permission::EMPLOYEES_VIEW) || $this->isCompanyHR($user)) {
            return true;
        }

        return $this->can($user, Permission::EMPLOYEES_VIEW_TEAM)
            && isset($employee->reports_to)
            && $employee->reports_to === $user->employeeId();
    }

    public function create(User $user): bool
    {
        return $this->can($user, Permission::EMPLOYEES_CREATE) || $this->isCompanyHR($user);
    }

    public function update(User $user, mixed $employee): bool
    {
        return $this->recordInCurrentOrg($employee)
            && ($this->can($user, Permission::EMPLOYEES_UPDATE) || $this->isCompanyHR($user));
    }

    /**
     * Ending somebody's employment.
     *
     * Separate from update because it is not an edit: it stops their pay, ends
     * their access and is the one action here that a person finds out about
     * whether or not it was meant. `employees.exit` exists in the catalogue for
     * exactly this and was never consulted — the request gating it asked
     * whether the caller's role was called HR.
     */
    public function exit(User $user, mixed $employee): bool
    {
        return $this->recordInCurrentOrg($employee)
            && ($this->can($user, Permission::EMPLOYEES_EXIT) || $this->isCompanyHR($user));
    }

    /**
     * Moving somebody to another company in the group.
     *
     * Held in *both* organisations, checked by the caller. It is the one action
     * that crosses a tenancy boundary, and holding it in the destination alone
     * would let one company help itself to another's staff; holding it in the
     * origin alone would let a company post people into an organisation that
     * has never agreed to employ them.
     *
     * Group HR passes on the role rather than the permission, because somebody
     * who oversees every organisation already holds both ends by definition.
     */
    public function transfer(User $user, mixed $employee = null): bool
    {
        return $this->can($user, Permission::EMPLOYEES_TRANSFER) || $this->isGlobalHR($user);
    }

    /**
     * Deleting is not exiting.
     *
     * An employee who has left is exited and kept — payroll, tax and any
     * dispute afterwards all need the record. Deletion is for a row that should
     * never have existed, which is why it stays with group HR.
     */
    public function delete(User $user, mixed $employee): bool
    {
        // isGlobalHR (group admin) legitimately reaches every org, and
        // recordInCurrentOrg() returns true for them (no org in context), so
        // this narrows org-scoped deleters without constraining group HR.
        return $this->recordInCurrentOrg($employee)
            && ($this->can($user, Permission::EMPLOYEES_DELETE) || $this->isGlobalHR($user));
    }

    /**
     * The record belongs to the organisation currently in context — the tenancy
     * check the record-level abilities were missing. Employee carries no org
     * global scope (its column is primary_organization_id), so this is the only
     * thing standing between a permission held in one org and another org's
     * record.
     *
     * "Belongs to" includes secondments: a host org must be able to open a
     * person placed into it, so membership is checked the same way the
     * directory scope checks it (primary org OR an active placement), not by a
     * bare primary_organization_id comparison. A group admin with no org in
     * context is unconstrained (context org null → allowed). A non-Employee or
     * non-model argument (there are none today) denies by default.
     */
    private function recordInCurrentOrg(mixed $employee): bool
    {
        if (! $employee instanceof Model) {
            return false;
        }

        $orgId = app()->bound('current.organization_id') ? app('current.organization_id') : null;

        // No org in context — group-wide administration. Preserve existing behaviour.
        if ($orgId === null) {
            return true;
        }

        if ($employee instanceof Employee) {
            return $employee->isMemberOfOrganization((int) $orgId);
        }

        return $this->inCurrentOrg($employee);
    }
}
