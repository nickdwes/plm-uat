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
        $query = Employee::query();

        /*
         * Employed here or placed here.
         *
         * This used to be `primary_organization_id` alone, which meant somebody
         * seconded into an organisation — at any allocation, up to their whole
         * week — did not appear in that organisation's directory at all. The
         * placement was recorded, carried a department and a manager, and was
         * invisible to everybody except the person who typed it in.
         */
        if ($organizationId !== null) {
            $query->forOrganization($organizationId);
        }

        $page = $query
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
            /*
             * Drafts are hidden unless asked for by name.
             *
             * A record halfway through the onboarding wizard is not somebody
             * who works here yet, and listing it beside people who do is how a
             * headcount stops being a headcount.
             */
            ->unless($filters['status'] ?? null, fn ($q) => $q->where('status', '!=', 'draft'))
            ->when($filters['employment_type'] ?? null, fn ($q, $t) => $q->where('employment_type', $t))
            /*
             * "On leave" is not a status — it is a fact about today, derived
             * from approved leave. Filtering the same listing rather than
             * serving a different one keeps the tab a strict subset of the
             * rows without it.
             */
            ->when(
                ($filters['on_leave'] ?? null),
                fn ($q) => $q->whereIn('id', \App\Services\HR\DirectoryFigures::onLeaveToday($organizationId)),
            )
            // primaryOrganization so the listing can name a visitor's employer
            // without a query per row.
            ->with(['department', 'designation', 'jobGrade', 'manager', 'primaryOrganization'])
            ->orderBy('last_name')
            ->paginate($perPage);

        /*
         * Show each visitor the job they do here. Their own department and
         * title belong to their home organisation and are invisible in this
         * one — both tables are tenant-scoped — so without this the directory
         * shows a blank where the job should be.
         */
        Employee::attachPlacements($page->getCollection(), $organizationId);

        return $page;
    }

    public function create(int $organizationId, array $data): Employee
    {
        $secondments = $data['secondments'] ?? [];
        unset($data['secondments']);

        $data['primary_organization_id'] = $organizationId;
        $data['employee_number']         = $this->generateEmployeeNumber();

        /*
         * The wizard's Save as Draft.
         *
         * This was hardcoded to active, which made a half-filled record a live
         * employee the moment the first step was saved — counted in headcount,
         * listed beside people who actually work here, and eligible for
         * payroll. Whatever the caller asked for is respected, and active
         * remains the default for everything that does not ask.
         */
        $data['status'] = $data['status'] ?? 'active';

        $employee = Employee::create($data);

        // Seed default onboarding checklists immediately after creation
        $this->onboardingService->seedEmployee($employee->id);

        $this->applySecondments($employee, $secondments);

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
            /*
             * Sync: drop the secondary organisations and re-attach the list.
             *
             * The pivot column is `organization_id`. This filtered on `org_id`,
             * which does not exist on the table, so every edit that carried
             * secondments failed on the way to the database rather than saving
             * the wrong thing.
             */
            $employee->organizations()
                ->wherePivot('organization_id', '!=', $employee->primary_organization_id)
                ->detach();

            $this->applySecondments($employee, $secondments);
        }

        return $employee->load(['primaryOrganization', 'department', 'designation', 'jobGrade', 'manager']);
    }

    public function get(int $id): Employee
    {
        $orgId = app('current.organization_id');
        // Secondments are eager-loaded so the onboarding wizard can read back
        // what it wrote — without them the resource omits the key entirely and
        // an edit silently drops every secondary assignment.
        return Employee::with([
            'primaryOrganization', 'department', 'designation', 'jobGrade', 'manager',
            'secondments',
        ])
            // Same rule as the listing: an organisation that can see somebody
            // in its directory has to be able to open them.
            ->when($orgId, fn ($q) => $q->forOrganization($orgId))
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

    /**
     * Attach a whole list of secondments to somebody.
     *
     * Two things this fixes over doing it inline.
     *
     * `engagement_type` — why they are in the second organisation: seconded, a
     * shared service, an advisory seat. It was accepted by the form request and
     * then dropped on the floor here, so the column was never written and the
     * Scope of Engagement panel appeared to save a field that went nowhere.
     *
     * The allocation cap. Adding secondments one at a time through
     * `storeSecondment` is refused past 100%, but the onboarding wizard sends
     * them as a list on create — the same promise the week cannot keep, arriving
     * by a different door. Someone at 60% and 60% is over-committed however the
     * rows got there.
     */
    private function applySecondments(Employee $employee, array $secondments): void
    {
        $running = 0;

        foreach ($secondments as $s) {
            $organizationId = (int) $s['org_id'];

            // Their home organisation is not one of their secondments.
            if ($organizationId === (int) $employee->primary_organization_id) {
                continue;
            }

            $running += (int) ($s['allocation_pct'] ?? 100);

            abort_if(
                $running > 100,
                422,
                "That would put them at {$running}% across organisations.",
            );

            $this->linkToOrganization($employee, $organizationId, [
                'role'            => $s['role'] ?? null,
                'engagement_type' => $s['engagement_type'] ?? null,
                'access_scope'    => $s['scope'] ?? 'operational',
                'allocation_pct'  => $s['allocation_pct'] ?? 100,
                'is_billable'     => $s['is_billable'] ?? false,
                'effective_from'  => $s['start_date'] ?? now()->toDateString(),
                'effective_to'    => $s['end_date'] ?? null,
                'notes'           => $s['notes'] ?? null,
            ]);
        }
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
