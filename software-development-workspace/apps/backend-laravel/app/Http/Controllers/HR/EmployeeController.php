<?php

namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\CreateEmployeeRequest;
use App\Http\Requests\Employee\ExitEmployeeRequest;
use App\Http\Requests\Employee\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Services\HR\EmployeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EmployeeController extends Controller
{
    public function __construct(private readonly EmployeeService $service) {}

    /**
     * GET /api/v1/employees
     * List employees for the current organization (manager+).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', \App\Models\Employee::class);

        $employees = $this->service->list(
            organizationId: app('current.organization_id'),
            filters: $request->only(['search', 'department_id', 'status', 'employment_type']),
            perPage: (int) $request->input('per_page', 15),
        );

        return EmployeeResource::collection($employees);
    }

    /**
     * POST /api/v1/employees
     * Create a new employee (HR only).
     */
    public function store(CreateEmployeeRequest $request): EmployeeResource
    {
        $employee = $this->service->create(
            organizationId: app('current.organization_id'),
            data: $request->validated(),
        );

        return new EmployeeResource($employee->load(['primaryOrganization', 'department', 'designation', 'jobGrade']));
    }

    /**
     * GET /api/v1/employees/{employee}
     * View employee detail (scoped by role).
     */
    public function show(int $employee): EmployeeResource
    {
        $record = $this->service->get($employee);
        $this->authorize('view', $record);

        $record->load(['primaryOrganization', 'department', 'designation', 'jobGrade', 'manager', 'emergencyContacts']);

        return new EmployeeResource($record);
    }

    /**
     * PUT /api/v1/employees/{employee}
     * Update employee (HR only).
     */
    public function update(UpdateEmployeeRequest $request, int $employee): EmployeeResource
    {
        $record = $this->service->update($employee, $request->validated());

        return new EmployeeResource($record);
    }

    /**
     * POST /api/v1/employees/{employee}/exit
     * Mark employee as exited (HR only).
     */
    public function exit(ExitEmployeeRequest $request, int $employee): EmployeeResource
    {
        $record = $this->service->exit(
            id: $employee,
            dateExited: $request->input('date_exited'),
            exitReason: $request->input('exit_reason'),
        );

        return new EmployeeResource($record);
    }
}
