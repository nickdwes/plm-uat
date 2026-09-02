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
 *   reporting   the subject reports directly to the acting user (one level —
 *               the house convention; policies check it inline)
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
     * The user's team, as employee ids: themself plus their DIRECT reports.
     *
     * One level down on purpose — the house convention every inline policy
     * check already follows. A deep-walking reportsTo() lived here once with
     * zero callers; if a real need for whole-subtree scoping arrives, revive
     * it from git rather than widening this, because "my team" and "everyone
     * under me transitively" are different promises to make a manager.
     *
     * @return int[] empty when the user has no employee record.
     */
    public static function teamEmployeeIds(User $user): array
    {
        $employeeId = $user->employeeId();

        if ($employeeId === null) {
            return [];
        }

        return [
            $employeeId,
            ...Employee::where('reports_to', $employeeId)->pluck('id')->map(fn ($id) => (int) $id),
        ];
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
