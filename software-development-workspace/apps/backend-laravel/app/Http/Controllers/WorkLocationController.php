<?php

namespace App\Http\Controllers;

use App\Http\Resources\WorkLocationCheckInResource;
use App\Models\Employee;
use App\Models\WorkLocationCheckIn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkLocationController extends Controller
{
    private function ownEmployee(Request $request): Employee
    {
        $employee = $request->user()->employee;
        abort_if(!$employee, 404, 'No employee record linked to your account.');

        return $employee;
    }

    /** GET /api/v1/work-location/today */
    public function today(Request $request): JsonResponse
    {
        $employee = $this->ownEmployee($request);

        $current = WorkLocationCheckIn::where('employee_id', $employee->id)
            ->whereNull('checked_out_at')
            ->latest('checked_in_at')
            ->first();

        $today = WorkLocationCheckIn::where('employee_id', $employee->id)
            ->whereDate('checked_in_at', today())
            ->orderByDesc('checked_in_at')
            ->get();

        return response()->json([
            'current' => $current ? new WorkLocationCheckInResource($current) : null,
            'today'   => WorkLocationCheckInResource::collection($today),
        ]);
    }

    /** GET /api/v1/work-location/history */
    public function history(Request $request): JsonResponse
    {
        $employee = $this->ownEmployee($request);

        $items = WorkLocationCheckIn::where('employee_id', $employee->id)
            ->orderByDesc('checked_in_at')
            ->limit(30)
            ->get();

        return response()->json(['data' => WorkLocationCheckInResource::collection($items)]);
    }

    /** POST /api/v1/work-location/check-in */
    public function checkIn(Request $request): WorkLocationCheckInResource
    {
        $employee = $this->ownEmployee($request);

        $data = $request->validate([
            'location_type' => ['required', 'in:office,home,customer_site'],
            'customer_name' => ['required_if:location_type,customer_site', 'nullable', 'string', 'max:150'],
            'notes'         => ['nullable', 'string', 'max:500'],
        ]);

        // Auto-close any currently open check-in before starting a new one.
        WorkLocationCheckIn::where('employee_id', $employee->id)
            ->whereNull('checked_out_at')
            ->update(['checked_out_at' => now()]);

        $checkIn = WorkLocationCheckIn::create([
            'organization_id' => app('current.organization_id'),
            'employee_id'     => $employee->id,
            'location_type'   => $data['location_type'],
            'customer_name'   => $data['location_type'] === 'customer_site' ? ($data['customer_name'] ?? null) : null,
            'notes'           => $data['notes'] ?? null,
            'checked_in_at'   => now(),
        ]);

        return new WorkLocationCheckInResource($checkIn);
    }

    /** POST /api/v1/work-location/check-out */
    public function checkOut(Request $request): WorkLocationCheckInResource
    {
        $employee = $this->ownEmployee($request);

        $open = WorkLocationCheckIn::where('employee_id', $employee->id)
            ->whereNull('checked_out_at')
            ->latest('checked_in_at')
            ->first();

        abort_if(!$open, 422, 'You are not currently checked in.');

        $open->update(['checked_out_at' => now()]);

        return new WorkLocationCheckInResource($open->fresh());
    }

    /** GET /api/v1/work-location/team */
    public function team(Request $request): JsonResponse
    {
        $user  = $request->user();
        $orgId = app('current.organization_id');

        abort_unless($user->isManagerIn($orgId), 403);

        $query = Employee::withoutGlobalScope('organization')
            ->forOrganization($orgId)
            ->active();

        if (!$user->isOrgHR($orgId)) {
            $managerEmployeeId = $user->employee?->id;
            $query->where('reports_to', $managerEmployeeId);
        }

        $employees = $query->get(['id', 'first_name', 'last_name', 'employee_number', 'photo_url']);

        $checkIns = WorkLocationCheckIn::whereIn('employee_id', $employees->pluck('id'))
            ->whereDate('checked_in_at', today())
            ->whereNull('checked_out_at')
            ->get()
            ->keyBy('employee_id');

        $data = $employees->map(fn (Employee $e) => [
            'employee'  => [
                'id'              => $e->id,
                'full_name'       => $e->full_name,
                'employee_number' => $e->employee_number,
                'photo_url'       => $e->photo_url,
            ],
            'check_in' => $checkIns->has($e->id) ? new WorkLocationCheckInResource($checkIns->get($e->id)) : null,
        ]);

        return response()->json(['data' => $data]);
    }
}
