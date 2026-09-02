<?php

namespace App\Services\Project;

use App\Models\Project\ProjectPerson;

/**
 * How much of a person is already committed to delivery.
 *
 * Sums the capacity a person is allocated across their delivery-team seats on
 * live projects, respecting assignment windows — so an assignment can be
 * checked against what is left of them before it is made.
 */
class ResourceAvailability
{
    /**
     * Total capacity (%) the employee is allocated across live projects whose
     * assignment window overlaps [from, to]. A seat with no window is treated
     * as always overlapping. `$exceptPersonId` drops one seat from the sum (so
     * an edit doesn't count itself twice).
     */
    public function totalAllocation(
        int $employeeId,
        int $orgId,
        ?string $from = null,
        ?string $to = null,
        ?int $exceptPersonId = null,
    ): int {
        $q = ProjectPerson::where('organization_id', $orgId)
            ->where('employee_id', $employeeId)
            ->where('role_kind', ProjectPerson::KIND_MEMBER)
            ->whereNotNull('capacity_percent')
            ->whereHas('project', fn ($p) => $p->whereNotIn('status', ['delivered', 'cancelled']));

        if ($exceptPersonId) {
            $q->where('id', '!=', $exceptPersonId);
        }

        // Overlap only matters when both this seat and the query carry a window.
        if ($from && $to) {
            $q->where(function ($w) use ($from, $to) {
                $w->whereNull('assigned_from')
                    ->orWhereNull('assigned_to')
                    ->orWhere(fn ($x) => $x->whereDate('assigned_from', '<=', $to)->whereDate('assigned_to', '>=', $from));
            });
        }

        return (int) $q->sum('capacity_percent');
    }
}
