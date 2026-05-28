<?php

namespace App\Services\Dashboard;

use App\Models\Employee;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    /**
     * Organization-level dashboard (single org view).
     * Always filters by the resolved organization_id in context.
     */
    public function organizationSummary(int $organizationId): array
    {
        $org = Organization::findOrFail($organizationId);

        $headcountByStatus = Employee::withoutGlobalScope('organization')
            ->where('primary_organization_id', $organizationId)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        $headcountByDept = Employee::withoutGlobalScope('organization')
            ->where('primary_organization_id', $organizationId)
            ->where('status', 'active')
            ->join('departments', 'employees.department_id', '=', 'departments.id')
            ->selectRaw('departments.name as department, COUNT(employees.id) as total')
            ->groupBy('departments.id', 'departments.name')
            ->orderByDesc('total')
            ->get()
            ->toArray();

        $headcountByType = Employee::withoutGlobalScope('organization')
            ->where('primary_organization_id', $organizationId)
            ->where('status', 'active')
            ->selectRaw('employment_type, COUNT(*) as total')
            ->groupBy('employment_type')
            ->pluck('total', 'employment_type')
            ->toArray();

        $recentJoiners = Employee::withoutGlobalScope('organization')
            ->where('primary_organization_id', $organizationId)
            ->where('date_hired', '>=', now()->subDays(30))
            ->orderByDesc('date_hired')
            ->limit(10)
            ->get(['id', 'employee_number', 'first_name', 'last_name', 'date_hired'])
            ->toArray();

        $sharedCount = DB::table('employee_organization_map')
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->count();

        return [
            'organization'       => ['id' => $org->id, 'name' => $org->name, 'slug' => $org->slug],
            'headcount'          => array_sum($headcountByStatus),
            'active_headcount'   => $headcountByStatus['active'] ?? 0,
            'by_status'          => $headcountByStatus,
            'by_department'      => $headcountByDept,
            'by_employment_type' => $headcountByType,
            'shared_employees'   => $sharedCount,
            'recent_joiners'     => $recentJoiners,
        ];
    }

    /**
     * Group-level dashboard (cross-org view — Group Admin only).
     */
    public function groupSummary(): array
    {
        $orgs = Organization::where('is_active', true)->get();

        $perOrg = $orgs->map(function (Organization $org) {
            $total = Employee::withoutGlobalScope('organization')
                ->where('primary_organization_id', $org->id)
                ->where('status', 'active')
                ->count();

            $shared = DB::table('employee_organization_map')
                ->where('organization_id', $org->id)
                ->where('is_active', true)
                ->count();

            return [
                'organization_id'   => $org->id,
                'name'              => $org->name,
                'slug'              => $org->slug,
                'active_headcount'  => $total,
                'shared_employees'  => $shared,
            ];
        });

        $totalWorkforce = Employee::withoutGlobalScope('organization')
            ->where('status', 'active')
            ->count();

        $totalShared = Employee::withoutGlobalScope('organization')
            ->where('is_shared', true)
            ->where('status', 'active')
            ->count();

        $byEmploymentType = Employee::withoutGlobalScope('organization')
            ->where('status', 'active')
            ->selectRaw('employment_type, COUNT(*) as total')
            ->groupBy('employment_type')
            ->pluck('total', 'employment_type')
            ->toArray();

        return [
            'total_workforce'      => $totalWorkforce,
            'total_shared_staff'   => $totalShared,
            'organizations_count'  => $orgs->count(),
            'by_organization'      => $perOrg->values()->toArray(),
            'by_employment_type'   => $byEmploymentType,
        ];
    }
}
