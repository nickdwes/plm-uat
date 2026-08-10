<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;
use App\Support\Authorization\Permission;

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
        if ($this->can($user, Permission::EMPLOYEES_VIEW) || $this->isCompanyHR($user)) {
            return true;
        }

        if (isset($employee->user_id) && $user->id === $employee->user_id) {
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
        return $this->can($user, Permission::EMPLOYEES_UPDATE) || $this->isCompanyHR($user);
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
        return $this->can($user, Permission::EMPLOYEES_EXIT) || $this->isCompanyHR($user);
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
        return $this->can($user, Permission::EMPLOYEES_DELETE) || $this->isGlobalHR($user);
    }
}
