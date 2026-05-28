<?php

namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeVehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeVehicleController extends Controller
{
    private function resolveEmployee(int $employeeId): Employee
    {
        $employee = Employee::findOrFail($employeeId);
        $this->authorize('view', $employee);
        return $employee;
    }

    private function isHR(): bool
    {
        return request()->user()->isOrgHR(app('current.organization_id'));
    }

    private function isSelf(Employee $employee): bool
    {
        return request()->user()->id === $employee->user_id;
    }

    /** GET /api/v1/employees/{employee}/vehicles */
    public function index(int $employee): JsonResponse
    {
        $emp = $this->resolveEmployee($employee);

        if (!$this->isHR() && !$this->isSelf($emp)) {
            abort(403, 'Access restricted to HR or the employee.');
        }

        return response()->json(['data' => $emp->vehicles()->get()]);
    }

    /** POST /api/v1/employees/{employee}/vehicles */
    public function store(Request $request, int $employee): JsonResponse
    {
        $emp = $this->resolveEmployee($employee);

        if (!$this->isHR() && !$this->isSelf($emp)) {
            abort(403, 'Access restricted to HR or the employee.');
        }

        $data = $request->validate([
            'registration_number' => 'required|string|max:20',
            'make'                => 'nullable|string|max:100',
            'model'               => 'nullable|string|max:100',
            'color'               => 'nullable|string|max:50',
            'year'                => 'nullable|integer|min:1900|max:' . (date('Y') + 1),
            'parking_slot'        => 'nullable|string|max:20',
            'notes'               => 'nullable|string|max:500',
        ]);

        $vehicle = $emp->vehicles()->create($data);
        return response()->json(['data' => $vehicle], 201);
    }

    /** PUT /api/v1/employees/{employee}/vehicles/{vehicle} */
    public function update(Request $request, int $employee, EmployeeVehicle $vehicle): JsonResponse
    {
        $emp = $this->resolveEmployee($employee);

        if (!$this->isHR() && !$this->isSelf($emp)) {
            abort(403, 'Access restricted to HR or the employee.');
        }

        abort_if($vehicle->employee_id !== $emp->id, 404);

        $data = $request->validate([
            'registration_number' => 'sometimes|string|max:20',
            'make'                => 'nullable|string|max:100',
            'model'               => 'nullable|string|max:100',
            'color'               => 'nullable|string|max:50',
            'year'                => 'nullable|integer|min:1900|max:' . (date('Y') + 1),
            'parking_slot'        => 'nullable|string|max:20',
            'notes'               => 'nullable|string|max:500',
        ]);

        $vehicle->update($data);
        return response()->json(['data' => $vehicle->fresh()]);
    }

    /** DELETE /api/v1/employees/{employee}/vehicles/{vehicle} */
    public function destroy(int $employee, EmployeeVehicle $vehicle): JsonResponse
    {
        $emp = $this->resolveEmployee($employee);

        if (!$this->isHR() && !$this->isSelf($emp)) {
            abort(403, 'Access restricted to HR or the employee.');
        }

        abort_if($vehicle->employee_id !== $emp->id, 404);
        $vehicle->delete();
        return response()->json(null, 204);
    }
}
