<?php

namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\CreateEmployeeRequest;
use App\Http\Requests\Employee\ExitEmployeeRequest;
use App\Http\Requests\Employee\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Models\EmployeeQualification;
use App\Models\EmployeeSecondment;
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

    public function show(int $employee): EmployeeResource
    {
        $record = $this->service->get($employee);
        $this->authorize('view', $record);

        $record->load([
            'primaryOrganization', 'department', 'designation', 'jobGrade',
            'manager', 'emergencyContacts',
            'directReports.designation',
            'qualifications',
            'secondments',
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

    public function storeSecondment(Request $request, int $employee): JsonResponse
    {
        $record = Employee::withoutGlobalScope('organization')->findOrFail($employee);
        $this->authorize('update', $record);

        $data = $request->validate([
            'organization_name' => ['required', 'string', 'max:200'],
            'role'              => ['nullable', 'string', 'max:150'],
            'start_date'        => ['required', 'date'],
            'end_date'          => ['nullable', 'date', 'after_or_equal:start_date'],
            'notes'             => ['nullable', 'string', 'max:1000'],
        ]);

        $secondment = $record->secondments()->create($data);

        return response()->json(['data' => [
            'id'                => $secondment->id,
            'organization_name' => $secondment->organization_name,
            'role'              => $secondment->role,
            'start_date'        => $secondment->start_date?->toDateString(),
            'end_date'          => $secondment->end_date?->toDateString(),
            'notes'             => $secondment->notes,
        ]], 201);
    }

    public function destroySecondment(int $employee, int $secondment): JsonResponse
    {
        $record = Employee::withoutGlobalScope('organization')->findOrFail($employee);
        $this->authorize('update', $record);

        EmployeeSecondment::where('employee_id', $employee)->findOrFail($secondment)->delete();

        return response()->json(null, 204);
    }
}
