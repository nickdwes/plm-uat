<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Authorization\ResourceAttributes;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared base for every policy.
 *
 * Authorization here is two questions, asked in order:
 *
 *   1. RBAC  — does the role grant this action at all?  ->  can()
 *   2. ABAC  — does it extend to *this* record?         ->  canTouch()
 *
 * The role helpers below (isGlobalHR, isCompanyHR, isManager, isEmployee)
 * predate the permission catalogue and are retained unchanged. Existing
 * policies keep working exactly as before; the permission-driven helpers
 * are additive, so modules migrate one at a time rather than in a single
 * sweep that would need every screen re-verified at once.
 */
abstract class BasePolicy
{
    use HandlesAuthorization;

    // ── Role helpers (pre-existing; unchanged behaviour) ──────────────────────

    protected function isGlobalHR(User $user): bool
    {
        return $user->isGroupAdmin();
    }

    protected function isCompanyHR(User $user): bool
    {
        return $user->isOrgHR(app('current.organization_id'));
    }

    protected function isManager(User $user): bool
    {
        return $user->isManagerIn(app('current.organization_id'));
    }

    protected function isEmployee(User $user): bool
    {
        return $user->hasRole('employee', app('current.organization_id')) || $this->isManager($user);
    }

    // ── Permission helpers (RBAC) ─────────────────────────────────────────────

    /** Does the user's role grant this permission in the current organization? */
    protected function can(User $user, string $permission): bool
    {
        return $user->hasPermission($permission);
    }

    protected function canAny(User $user, string ...$permissions): bool
    {
        return $user->hasAnyPermission(...$permissions);
    }

    // ── Combined helpers (RBAC + ABAC) ────────────────────────────────────────

    /**
     * The full check: permission granted AND the record is in the tenant
     * currently in context.
     *
     * Tenancy is applied here rather than left to each caller because it is
     * the check most easily forgotten, and the one whose omission is least
     * visible — every role check still passes while records leak across
     * organizations.
     */
    protected function canTouch(User $user, string $permission, ?Model $resource): bool
    {
        return $this->can($user, $permission)
            && ResourceAttributes::inCurrentOrganization($resource);
    }

    /**
     * Broad permission on any record, or a narrower one limited to the
     * user's own.
     *
     * Expresses the common shape directly: HR may view any employee, while
     * an employee may view only themselves. Tenancy applies to both.
     */
    protected function canTouchOwn(
        User $user,
        string $broadPermission,
        string $ownPermission,
        ?Model $resource
    ): bool {
        if (! ResourceAttributes::inCurrentOrganization($resource)) {
            return false;
        }

        if ($this->can($user, $broadPermission)) {
            return true;
        }

        return $this->can($user, $ownPermission) && $user->owns($resource);
    }

    /**
     * Broad permission, or a narrower one limited to the user's reporting
     * line.
     *
     * $subject is the employee the record concerns — for a leave request
     * that is its employee, not the request itself, since the reporting
     * line is a property of people.
     */
    protected function canTouchTeam(
        User $user,
        string $broadPermission,
        string $teamPermission,
        ?Model $resource,
        ?Model $subject = null
    ): bool {
        if (! ResourceAttributes::inCurrentOrganization($resource)) {
            return false;
        }

        if ($this->can($user, $broadPermission)) {
            return true;
        }

        return $this->can($user, $teamPermission)
            && $user->manages($subject ?? $resource);
    }

    /** Convenience passthrough so policies need not import the class. */
    protected function inCurrentOrg(?Model $resource): bool
    {
        return ResourceAttributes::inCurrentOrganization($resource);
    }
}
