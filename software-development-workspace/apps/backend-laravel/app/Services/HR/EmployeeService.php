<?php

namespace App\Services\HR;

use App\Models\Employee;
use App\Repositories\HR\EmployeeRepository;
use App\Services\BaseService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class EmployeeService extends BaseService
{
    public function __construct(
        EmployeeRepository $repository,
        private readonly OnboardingService $onboardingService,
    ) {
        parent::__construct($repository);
    }

    public function list(?int $organizationId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Employee::withoutGlobalScope('organization');

        if ($organizationId !== null) {
            $query->where('primary_organization_id', $organizationId);
        }

        return $query
            ->when($filters['search'] ?? null, fn ($q, $s) =>
                $q->where(fn ($q) =>
                    $q->where('first_name', 'like', "%{$s}%")
                      ->orWhere('last_name', 'like', "%{$s}%")
                      ->orWhere('employee_number', 'like', "%{$s}%")
                      ->orWhere('work_email', 'like', "%{$s}%")
                )
            )
            ->when($filters['department_id'] ?? null, fn ($q, $d) => $q->where('department_id', $d))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['employment_type'] ?? null, fn ($q, $t) => $q->where('employment_type', $t))
            ->with(['department', 'designation', 'jobGrade', 'manager'])
            ->orderBy('last_name')
            ->paginate($perPage);
    }

    public function create(int $organizationId, array $data): Employee
    {
        $secondments = $data['secondments'] ?? [];
        unset($data['secondments']);

        $data['primary_organization_id'] = $organizationId;
        $data['employee_number']         = $this->generateEmployeeNumber();
        $data['status']                  = 'active';

        $employee = Employee::create($data);

        // Seed default onboarding checklists immediately after creation
        $this->onboardingService->seedEmployee($employee->id);

        foreach ($secondments as $s) {
            $this->linkToOrganization($employee, (int) $s['org_id'], [
                'role'           => $s['role'] ?? null,
                'access_scope'   => $s['scope'] ?? 'operational',
                'allocation_pct' => $s['allocation_pct'] ?? 100,
                'is_billable'    => $s['is_billable'] ?? false,
                'effective_from' => $s['start_date'] ?? now()->toDateString(),
                'effective_to'   => $s['end_date'] ?? null,
                'notes'          => $s['notes'] ?? null,
            ]);
        }

        return $employee;
    }

    public function update(int $id, array $data): Employee
    {
        $secondments = $data['secondments'] ?? null;
        unset($data['secondments']);

        $orgId = app('current.organization_id');
        $employee = Employee::when($orgId, fn($q) => $q->where('primary_organization_id', $orgId))
            ->withoutGlobalScope('organization')
            ->findOrFail($id);
        $employee->update($data);

        if ($secondments !== null) {
            // Sync secondments: detach all non-primary orgs, re-attach updated list
            $employee->organizations()->wherePivot('org_id', '!=', $employee->primary_organization_id)->detach();
            foreach ($secondments as $s) {
                $this->linkToOrganization($employee, (int) $s['org_id'], [
                    'role'           => $s['role'] ?? null,
                    'access_scope'   => $s['scope'] ?? 'operational',
                    'allocation_pct' => $s['allocation_pct'] ?? 100,
                    'is_billable'    => $s['is_billable'] ?? false,
                    'effective_from' => $s['start_date'] ?? now()->toDateString(),
                    'effective_to'   => $s['end_date'] ?? null,
                    'notes'          => $s['notes'] ?? null,
                ]);
            }
        }

        return $employee->load(['primaryOrganization', 'department', 'designation', 'jobGrade', 'manager']);
    }

    public function get(int $id): Employee
    {
        $orgId = app('current.organization_id');
        return Employee::with(['primaryOrganization', 'department', 'designation', 'jobGrade', 'manager'])
            ->withoutGlobalScope('organization')
            ->when($orgId, fn($q) => $q->where('primary_organization_id', $orgId))
            ->findOrFail($id);
    }

    public function exit(int $id, string $dateExited, string $exitReason): Employee
    {
        $orgId = app('current.organization_id');
        $employee = Employee::when($orgId, fn($q) => $q->where('primary_organization_id', $orgId))
            ->withoutGlobalScope('organization')
            ->findOrFail($id);

        $employee->update([
            'status'      => 'exited',
            'date_exited' => $dateExited,
            'exit_reason' => $exitReason,
        ]);

        return $employee;
    }

    public function linkToOrganization(Employee $employee, int $organizationId, array $context): void
    {
        $employee->organizations()->syncWithoutDetaching([
            $organizationId => array_merge([
                'is_active'      => true,
                'access_scope'   => 'operational',
                'effective_from' => now()->toDateString(),
                'allocation_pct' => 100,
                'is_billable'    => false,
            ], $context),
        ]);

        if (! $employee->is_shared) {
            $employee->update(['is_shared' => true]);
        }
    }

    private function generateEmployeeNumber(): string
    {
        $last = Employee::withoutGlobalScope('organization')
            ->withTrashed()
            ->orderByDesc('id')
            ->value('employee_number');

        $next = $last ? ((int) preg_replace('/\D/', '', $last)) + 1 : 1;

        return 'EMP-' . str_pad($next, 5, '0', STR_PAD_LEFT);
    }
}
