<?php

namespace App\Services\Travel;

use App\Models\Employee;
use Illuminate\Support\Facades\DB;

/**
 * Where a traveller sits, in the organisation the request is being raised in.
 *
 * This exists on its own because it is where cross-tenant mistakes are made.
 * An employee can belong to several organisations with a different department,
 * designation and manager in each, and the intuitive lookups — the employee's
 * `department_id`, their `primary_organization_id` — silently return the wrong
 * organisation's answer for anyone seconded. That routes a request to a
 * manager at another company, which reads as an ordinary approval queue entry
 * to everyone involved.
 */
class TravelPlacementResolver
{
    /**
     * Is this employee actually a member of this organisation?
     *
     * Checked explicitly because tenancy scoping does not supply it: a user
     * with access to two organisations could otherwise raise a request in one
     * on behalf of their position in the other.
     */
    public function isMember(int $employeeId, int $organizationId): bool
    {
        $primary = Employee::withoutGlobalScopes()
            ->whereKey($employeeId)
            ->where('primary_organization_id', $organizationId)
            ->exists();

        if ($primary) {
            return true;
        }

        return DB::table('employee_organization_map')
            ->where('employee_id', $employeeId)
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            // A secondment that has ended is not membership. Without this a
            // lapsed placement keeps approval rights indefinitely.
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', now()->toDateString()))
            ->exists();
    }

    /**
     * The department, functional unit and job grade for this employee in this
     * organisation.
     *
     * @return array{department_id: ?int, functional_unit_id: ?int, job_grade_id: ?int}
     */
    public function placement(int $employeeId, int $organizationId): array
    {
        $map = DB::table('employee_organization_map')
            ->where('employee_id', $employeeId)
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->first();

        $employee = Employee::withoutGlobalScopes()->find($employeeId);

        // Prefer the per-organisation placement; fall back to the employee's
        // own fields only when this is their primary organisation, so a
        // secondment never inherits the home organisation's department.
        $isPrimary = $employee?->primary_organization_id === $organizationId;

        $departmentId  = $map->department_id  ?? ($isPrimary ? $employee?->department_id : null);
        $designationId = $map->designation_id ?? ($isPrimary ? $employee?->designation_id : null);

        $functionalUnitId = $departmentId
            ? DB::table('departments')->where('id', $departmentId)->value('functional_unit_id')
            : null;

        $jobGradeId = $designationId
            ? DB::table('designations')->where('id', $designationId)->value('job_grade_id')
            : null;

        return [
            'department_id'      => $departmentId,
            'functional_unit_id' => $functionalUnitId,
            'job_grade_id'       => $jobGradeId,
        ];
    }

    /**
     * The employee expected to act at a structural stage, or null.
     *
     * Null is a real answer, not a failure: a department with no lead, or one
     * not attached to a functional unit, has nobody to route to. The caller
     * decides what to do about it, and the request should not be accepted
     * silently into a queue nobody owns.
     */
    public function approverFor(string $stage, array $placement): ?int
    {
        return match ($stage) {
            'department_head' => $placement['department_id']
                ? DB::table('departments')->where('id', $placement['department_id'])->value('lead_employee_id')
                : null,
            'function_head' => $placement['functional_unit_id']
                ? DB::table('org_functional_units')->where('id', $placement['functional_unit_id'])->value('lead_employee_id')
                : null,
            // finance and executive are role-based: whoever holds the
            // permission acts, so there is no named person until one does.
            default => null,
        };
    }
}
