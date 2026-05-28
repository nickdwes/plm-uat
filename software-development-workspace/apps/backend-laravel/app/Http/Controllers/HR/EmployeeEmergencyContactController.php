<?php

namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeEmergencyContact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeEmergencyContactController extends Controller
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

    /** GET /api/v1/employees/{employee}/emergency-contacts */
    public function index(int $employee): JsonResponse
    {
        $emp = $this->resolveEmployee($employee);

        if (!$this->isHR() && !$this->isSelf($emp)) {
            abort(403, 'Access restricted to HR or the employee.');
        }

        return response()->json(['data' => $emp->emergencyContacts()->orderByDesc('is_primary')->get()]);
    }

    /** POST /api/v1/employees/{employee}/emergency-contacts */
    public function store(Request $request, int $employee): JsonResponse
    {
        $emp = $this->resolveEmployee($employee);

        if (!$this->isHR() && !$this->isSelf($emp)) {
            abort(403, 'Access restricted to HR or the employee.');
        }

        $data = $request->validate([
            'name'         => 'required|string|max:150',
            'relationship' => 'required|string|max:80',
            'phone'        => 'required|string|max:30',
            'email'        => 'nullable|email|max:150',
            'is_primary'   => 'nullable|boolean',
        ]);

        // Only one primary per employee
        if (!empty($data['is_primary'])) {
            EmployeeEmergencyContact::where('employee_id', $emp->id)->update(['is_primary' => false]);
        }

        $contact = $emp->emergencyContacts()->create($data);
        return response()->json(['data' => $contact], 201);
    }

    /** PUT /api/v1/employees/{employee}/emergency-contacts/{contact} */
    public function update(Request $request, int $employee, EmployeeEmergencyContact $contact): JsonResponse
    {
        $emp = $this->resolveEmployee($employee);

        if (!$this->isHR() && !$this->isSelf($emp)) {
            abort(403, 'Access restricted to HR or the employee.');
        }

        abort_if($contact->employee_id !== $emp->id, 404);

        $data = $request->validate([
            'name'         => 'sometimes|string|max:150',
            'relationship' => 'sometimes|string|max:80',
            'phone'        => 'sometimes|string|max:30',
            'email'        => 'nullable|email|max:150',
            'is_primary'   => 'nullable|boolean',
        ]);

        if (!empty($data['is_primary'])) {
            EmployeeEmergencyContact::where('employee_id', $emp->id)
                ->where('id', '!=', $contact->id)
                ->update(['is_primary' => false]);
        }

        $contact->update($data);
        return response()->json(['data' => $contact->fresh()]);
    }

    /** DELETE /api/v1/employees/{employee}/emergency-contacts/{contact} */
    public function destroy(int $employee, EmployeeEmergencyContact $contact): JsonResponse
    {
        $emp = $this->resolveEmployee($employee);

        if (!$this->isHR() && !$this->isSelf($emp)) {
            abort(403, 'Access restricted to HR or the employee.');
        }

        abort_if($contact->employee_id !== $emp->id, 404);
        $contact->delete();
        return response()->json(null, 204);
    }
}
