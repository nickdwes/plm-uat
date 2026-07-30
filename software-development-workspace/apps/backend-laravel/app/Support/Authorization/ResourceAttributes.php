<?php

namespace App\Support\Authorization;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The attribute (ABAC) half of authorization.
 *
 * RBAC answers "may this role do this action at all?". These answers
 * "…to *this* record?". A dept_manager holding `leave.approve` may approve
 * leave, but only for someone in their reporting line — that second
 * question is answered here.
 *
 * Three attributes carry every decision in this system:
 *
 *   ownership   the record belongs to the acting user's own employee record
 *   reporting   the subject reports to the acting user, directly or further down
 *   tenancy     the record belongs to the organization in context
 *
 * Tenancy is the one that must never be skipped. Every other check assumes
 * the record is already in scope; `inCurrentOrganization()` is what makes
 * that assumption true, and omitting it is how a multi-tenant system leaks
 * across tenants while every role check still passes.
 */
final class ResourceAttributes
{
    /**
     * Does this record belong to the user's own employee record?
     *
     * Accepts an Employee (compared by id) or any model carrying an
     * `employee_id`. A user with no employee record owns nothing.
     */
    public static function isOwnedBy(User $user, ?Model $resource): bool
    {
        if ($resource === null) {
            return false;
        }

        $employeeId = $user->employeeId();

        if ($employeeId === null) {
            return false;
        }

        if ($resource instanceof Employee) {
            return (int) $resource->getKey() === $employeeId;
        }

        $attr = $resource->getAttribute('employee_id');

        return $attr !== null && (int) $attr === $employeeId;
    }

    /**
     * Is the subject somewhere beneath the user in the reporting line?
     *
     * Walks `employees.reports_to` upward from the subject rather than
     * expanding the manager's subtree downward — an employee has one chain
     * up but a manager may have a large tree below, so this is bounded by
     * org depth instead of headcount.
     *
     * $maxDepth guards against a cycle in the data (A reports to B reports
     * to A). Such a cycle should not exist, but authorization is the wrong
     * place to discover it by hanging.
     */
    public static function reportsTo(User $user, ?Model $subject, int $maxDepth = 12): bool
    {
        if (! $subject instanceof Employee) {
            return false;
        }

        $managerEmployeeId = $user->employeeId();

        if ($managerEmployeeId === null) {
            return false;
        }

        // Nobody manages themselves.
        if ((int) $subject->getKey() === $managerEmployeeId) {
            return false;
        }

        $seen    = [];
        $current = $subject->reports_to;

        for ($depth = 0; $current !== null && $depth < $maxDepth; $depth++) {
            $current = (int) $current;

            if ($current === $managerEmployeeId) {
                return true;
            }

            // Cycle detected — stop rather than loop.
            if (isset($seen[$current])) {
                return false;
            }
            $seen[$current] = true;

            $current = Employee::whereKey($current)->value('reports_to');
        }

        return false;
    }

    /**
     * Is the record inside the organization currently in context?
     *
     * A null context means group-wide (a group admin operating across all
     * organizations), which no record is excluded from.
     *
     * Records are matched on `organization_id`, falling back to
     * `primary_organization_id` for Employee. A record carrying neither is
     * treated as out of scope — failing closed, because a record we cannot
     * place is a record we cannot prove belongs to this tenant.
     */
    public static function inCurrentOrganization(?Model $resource, ?int $organizationId = null): bool
    {
        if ($resource === null) {
            return false;
        }

        $organizationId ??= app()->bound('current.organization_id')
            ? app('current.organization_id')
            : null;

        if ($organizationId === null) {
            return true;
        }

        foreach (['organization_id', 'primary_organization_id'] as $attribute) {
            $value = $resource->getAttribute($attribute);

            if ($value !== null) {
                return (int) $value === (int) $organizationId;
            }
        }

        return false;
    }
}
