<?php

namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\CreateEmployeeRequest;
use App\Http\Requests\Employee\ExitEmployeeRequest;
use App\Http\Requests\Employee\UpdateContactDetailsRequest;
use App\Http\Requests\Employee\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Models\EmployeeQualification;
use App\Services\HR\EmployeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;

class EmployeeController extends Controller
{
    public function __construct(private readonly EmployeeService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Employee::class);

        $employees = $this->service->list(
            organizationId: app('current.organization_id'),
            filters: $request->only(['search', 'department_id', 'status', 'employment_type']),
            perPage: (int) $request->input('per_page', 15),
        );

        return EmployeeResource::collection($employees);
    }

    public function store(CreateEmployeeRequest $request): EmployeeResource
    {
        $employee = $this->service->create(
            organizationId: app('current.organization_id'),
            data: $request->validated(),
        );

        return new EmployeeResource($employee->load(['primaryOrganization', 'department', 'designation', 'jobGrade']));
    }

    /**
     * GET /api/v1/employees/search — typeahead lookup for pickers.
     *
     * Deliberately narrow: enough to identify a person and nothing more.
     * A picker that returns the full employee resource hands salary, national
     * ID and medical fields to every screen that needs to choose a name.
     *
     * An equivalent already existed under the recruitment prefix. Left in
     * place so that module keeps working, but a general-purpose lookup does
     * not belong behind a feature route.
     */
    public function search(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Employee::class);

        $term = trim((string) $request->query('q', ''));

        /*
         * Scoped to the organisation in context.
         *
         * This had no organisation predicate at all, so a typeahead meant for
         * picking a colleague returned every name, work address and employee
         * number in the group — Konvergenz, Tiberbu, Visibility and Teedu
         * alike — to anybody who could reach the route. Tenancy is the boundary
         * the whole application is built on, and this walked straight through
         * it. A null organisation is a group-scope user, who is entitled to
         * everybody.
         */
        $orgId = app()->bound('current.organization_id') ? app('current.organization_id') : null;

        $employees = Employee::query()
            ->when($orgId, fn ($q) => $q->forOrganization($orgId))
            ->where('status', 'active')
            ->when($term !== '', function ($q) use ($term) {
                $like = '%' . $term . '%';
                $q->where(fn ($w) => $w
                    ->where('first_name', 'ilike', $like)
                    ->orWhere('last_name', 'ilike', $like)
                    ->orWhere('work_email', 'ilike', $like)
                    ->orWhere('employee_number', 'ilike', $like));
            })
            ->orderBy('first_name')
            ->limit(25)
            ->get(['id', 'first_name', 'last_name', 'employee_number', 'work_email']);

        return response()->json([
            'data' => $employees->map(fn (Employee $e) => [
                'id'              => $e->id,
                'full_name'       => trim($e->first_name . ' ' . $e->last_name),
                'employee_number' => $e->employee_number,
                'work_email'      => $e->work_email,
            ]),
        ]);
    }

    /** GET /api/v1/employees/me — the logged-in user's own employee record */
    public function me(Request $request): EmployeeResource
    {
        $employee = $request->user()->employee;
        abort_if(!$employee, 404, 'No employee record linked to your account.');

        $employee->load([
            'primaryOrganization', 'department', 'designation', 'jobGrade',
            'manager', 'emergencyContacts',
            'directReports.designation',
            'qualifications',
        ]);

        return new EmployeeResource($employee);
    }

    /** PUT /api/v1/employees/me/contact — self-service contact details edit */
    public function updateOwnContact(UpdateContactDetailsRequest $request): EmployeeResource
    {
        $employee = $request->user()->employee;
        abort_if(!$employee, 404, 'No employee record linked to your account.');

        $employee->update($request->validated());

        return new EmployeeResource($employee->fresh());
    }

    public function show(int $employee): EmployeeResource
    {
        $record = $this->service->get($employee);
        $this->authorize('view', $record);

        $record->load([
            'primaryOrganization', 'department', 'designation', 'jobGrade',
            'manager', 'emergencyContacts',
            'directReports.designation',
            'qualifications',
        ]);

        return new EmployeeResource($record);
    }

    public function update(UpdateEmployeeRequest $request, int $employee): EmployeeResource
    {
        $record = $this->service->update($employee, $request->validated());

        return new EmployeeResource($record);
    }

    public function exit(ExitEmployeeRequest $request, int $employee): EmployeeResource
    {
        $record = $this->service->exit(
            id: $employee,
            dateExited: $request->input('date_exited'),
            exitReason: $request->input('exit_reason'),
        );

        return new EmployeeResource($record);
    }

    // ── Profile photo ─────────────────────────────────────────────────────────

    public function uploadPhoto(Request $request, int $employee): JsonResponse
    {
        $record = Employee::withoutGlobalScope('organization')->findOrFail($employee);
        $this->authorize('update', $record);

        $request->validate([
            'photo' => ['required', 'image', 'max:5120', 'mimes:jpeg,jpg,png,webp'],
        ]);

        // Delete previous photo
        if ($record->photo_url) {
            $prev = ltrim(parse_url($record->photo_url, PHP_URL_PATH), '/');
            $prev = preg_replace('#^storage/#', '', $prev);
            Storage::disk('public')->delete($prev);
        }

        $path = $request->file('photo')->store("employee-photos", 'public');
        $url  = Storage::disk('public')->url($path);

        $record->update(['photo_url' => $url]);

        return response()->json(['data' => ['photo_url' => $url]]);
    }

    // ── Qualifications ────────────────────────────────────────────────────────

    public function storeQualification(Request $request, int $employee): JsonResponse
    {
        $record = Employee::withoutGlobalScope('organization')->findOrFail($employee);
        $this->authorize('update', $record);

        $data = $request->validate([
            'title'              => ['required', 'string', 'max:200'],
            'institution'        => ['nullable', 'string', 'max:200'],
            'qualification_type' => ['required', 'in:degree,diploma,certificate,professional'],
            'field_of_study'     => ['nullable', 'string', 'max:150'],
            'year_obtained'      => ['nullable', 'integer', 'min:1950', 'max:' . (date('Y') + 1)],
            'certificate_number' => ['nullable', 'string', 'max:100'],
            'notes'              => ['nullable', 'string', 'max:1000'],
        ]);

        $qual = $record->qualifications()->create($data);

        return response()->json(['data' => $qual], 201);
    }

    public function destroyQualification(int $employee, int $qualification): JsonResponse
    {
        $record = Employee::withoutGlobalScope('organization')->findOrFail($employee);
        $this->authorize('update', $record);

        EmployeeQualification::where('employee_id', $employee)->findOrFail($qualification)->delete();

        return response()->json(null, 204);
    }

    // ── Secondments ───────────────────────────────────────────────────────────

    /**
     * Put somebody into a second organisation for a while.
     *
     * Held on `employee_organization_map` rather than the free-text table it
     * replaced. The Scope of Engagement panel asks for a time allocation and
     * whether the time is billable, and the organisation is one of the tenants
     * rather than a name somebody types — none of which a free-text column can
     * answer, and answering "who is 50% billable to Tiberbu this quarter" is
     * the only reason anybody records a secondment.
     */
    public function storeSecondment(Request $request, int $employee): JsonResponse
    {
        $record = Employee::withoutGlobalScope('organization')->findOrFail($employee);
        $this->authorize('update', $record);

        $data = $request->validate([
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
            'role'            => ['nullable', 'string', 'max:150'],
            'engagement_type' => ['nullable', 'string', 'max:32'],
            'designation_id'  => ['nullable', 'integer', 'exists:designations,id'],
            'department_id'   => ['nullable', 'integer', 'exists:departments,id'],
            'reports_to'      => ['nullable', 'integer', 'exists:employees,id'],
            // Percent of their week. Nought would be a secondment that is not
            // happening, so the floor is one.
            'allocation_pct'  => ['nullable', 'integer', 'min:1', 'max:100'],
            'is_billable'     => ['sometimes', 'boolean'],
            'effective_from'  => ['required', 'date'],
            'effective_to'    => ['nullable', 'date', 'after_or_equal:effective_from'],
            'notes'           => ['nullable', 'string', 'max:1000'],
        ]);

        abort_if(
            (int) $data['organization_id'] === (int) $record->primary_organization_id,
            422,
            'That is already their primary organisation.',
        );

        /*
         * Seconded time is checked against seconded time, not against their
         * home organisation.
         *
         * The percentage means "how much of their week goes elsewhere" — the
         * remainder stays where they normally sit. Counting the primary row
         * would make the cap mean one thing when that row exists and another
         * when it does not, and would force HR to reduce home first before
         * every secondment.
         *
         * Somebody seconded at 60% and 60% is still a promise the week cannot
         * keep, and noticing that is the point of recording it at all.
         */
        $committed = $record->organizations()
            ->wherePivot('is_active', true)
            ->wherePivot('organization_id', '!=', $data['organization_id'])
            ->wherePivot('organization_id', '!=', $record->primary_organization_id)
            ->get()->sum(fn ($o) => (int) ($o->pivot->allocation_pct ?? 0));

        $asked = (int) ($data['allocation_pct'] ?? 0);

        abort_if(
            $committed + $asked > 100,
            422,
            "That would put them at " . ($committed + $asked) . "% across organisations.",
        );

        $record->organizations()->syncWithoutDetaching([
            $data['organization_id'] => [
                'role'            => $data['role'] ?? null,
                'engagement_type' => $data['engagement_type'] ?? null,
                'designation_id'  => $data['designation_id'] ?? null,
                'department_id'   => $data['department_id'] ?? null,
                'reports_to'      => $data['reports_to'] ?? null,
                'allocation_pct'  => $data['allocation_pct'] ?? null,
                'is_billable'     => $request->boolean('is_billable'),
                'effective_from'  => $data['effective_from'],
                'effective_to'    => $data['effective_to'] ?? null,
                'is_active'       => true,
                'notes'           => $data['notes'] ?? null,
                'access_scope'    => 'organization',
            ],
        ]);

        return response()->json(['data' => $this->secondments($record->fresh())], 201);
    }

    /**
     * End a secondment.
     *
     * Marked inactive rather than deleted: somebody was billable to that
     * organisation for three months and payroll, reporting and anybody
     * reconciling an invoice still need to see it.
     */
    public function destroySecondment(int $employee, int $secondment): JsonResponse
    {
        $record = Employee::withoutGlobalScope('organization')->findOrFail($employee);
        $this->authorize('update', $record);

        $record->organizations()->updateExistingPivot($secondment, [
            'is_active'    => false,
            'effective_to' => now()->toDateString(),
        ]);

        return response()->json(['data' => $this->secondments($record->fresh())]);
    }

    /** Every organisation besides their primary one. */
    private function secondments(Employee $record): array
    {
        return $record->organizations()
            ->wherePivot('organization_id', '!=', $record->primary_organization_id)
            ->get()
            ->map(fn ($org) => [
                'organization'    => ['id' => $org->id, 'name' => $org->name],
                'role'            => $org->pivot->role,
                'engagement_type' => $org->pivot->engagement_type,
                'allocation_pct'  => $org->pivot->allocation_pct,
                'is_billable'     => (bool) $org->pivot->is_billable,
                'effective_from'  => $org->pivot->effective_from,
                'effective_to'    => $org->pivot->effective_to,
                'is_active'       => (bool) $org->pivot->is_active,
                'notes'           => $org->pivot->notes,
            ])->values()->all();
    }
}
