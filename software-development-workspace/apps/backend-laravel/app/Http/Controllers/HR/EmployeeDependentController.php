<?php

namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeDependent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeDependentController extends Controller
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

    /** GET /api/v1/employees/{employee}/dependents */
    public function index(int $employee): JsonResponse
    {
        $emp = $this->resolveEmployee($employee);

        if (!$this->isHR() && !$this->isSelf($emp)) {
            abort(403, 'Access restricted to HR or the employee.');
        }

        return response()->json(['data' => $emp->dependents()->get()]);
    }

    /** POST /api/v1/employees/{employee}/dependents */
    public function store(Request $request, int $employee): JsonResponse
    {
        $emp = $this->resolveEmployee($employee);

        if (!$this->isHR() && !$this->isSelf($emp)) {
            abort(403, 'Access restricted to HR or the employee.');
        }

        $data = $request->validate([
            'first_name'         => 'required|string|max:100',
            'last_name'          => 'required|string|max:100',
            'middle_name'        => 'nullable|string|max:100',
            'relationship'       => 'required|string|max:80',
            'date_of_birth'      => 'nullable|date|before:today',
            'gender'             => 'nullable|in:male,female,other',
            'national_id'        => 'nullable|string|max:20',
            'passport_number'    => 'nullable|string|max:30',
            'school_institution' => 'nullable|string|max:200',
            'disability'         => 'nullable|string|max:255',
            'notes'              => 'nullable|string|max:1000',
        ]);

        $dependent = $emp->dependents()->create($data);
        return response()->json(['data' => $dependent], 201);
    }

    /** PUT /api/v1/employees/{employee}/dependents/{dependent} */
    public function update(Request $request, int $employee, EmployeeDependent $dependent): JsonResponse
    {
        $emp = $this->resolveEmployee($employee);

        if (!$this->isHR() && !$this->isSelf($emp)) {
            abort(403, 'Access restricted to HR or the employee.');
        }

        abort_if($dependent->employee_id !== $emp->id, 404);

        $data = $request->validate([
            'first_name'         => 'sometimes|string|max:100',
            'last_name'          => 'sometimes|string|max:100',
            'middle_name'        => 'nullable|string|max:100',
            'relationship'       => 'sometimes|string|max:80',
            'date_of_birth'      => 'nullable|date|before:today',
            'gender'             => 'nullable|in:male,female,other',
            'national_id'        => 'nullable|string|max:20',
            'passport_number'    => 'nullable|string|max:30',
            'school_institution' => 'nullable|string|max:200',
            'disability'         => 'nullable|string|max:255',
            'notes'              => 'nullable|string|max:1000',
        ]);

        $dependent->update($data);
        return response()->json(['data' => $dependent->fresh()]);
    }

    /** DELETE /api/v1/employees/{employee}/dependents/{dependent} */
    public function destroy(int $employee, EmployeeDependent $dependent): JsonResponse
    {
        $emp = $this->resolveEmployee($employee);

        if (!$this->isHR() && !$this->isSelf($emp)) {
            abort(403, 'Access restricted to HR or the employee.');
        }

        abort_if($dependent->employee_id !== $emp->id, 404);
        $dependent->delete();
        return response()->json(null, 204);
    }
}
