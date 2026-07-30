<?php

namespace App\Http\Controllers\Leave;

use App\Support\Authorization\Permission;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaveAdminController extends Controller
{
    // ── Leave Types ───────────────────────────────────────────────────────────

    public function indexTypes(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::LEAVE_VIEW), 403);

        $orgId = app('current.organization_id');
        $types = LeaveType::where('organization_id', $orgId)->orderBy('name')->get();
        return response()->json(['data' => $types]);
    }

    public function storeType(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::LEAVE_MANAGE), 403);

        $data = $request->validate([
            'name'              => 'required|string|max:100',
            'code'              => 'nullable|string|max:20',
            'days_per_year'     => 'required|integer|min:0',
            'is_paid'           => 'boolean',
            'carry_forward'     => 'boolean',
            'max_carry_days'    => 'integer|min:0',
            'requires_approval' => 'boolean',
            'is_active'         => 'boolean',
            'counting_method'   => 'in:business_days,calendar_days',
            'working_days'      => 'nullable|array',
            'working_days.*'    => 'integer|between:1,7',
            'allow_half_day'    => 'boolean',
            'allow_scattered'   => 'boolean',
        ]);
        $data['organization_id'] = app('current.organization_id');
        $type = LeaveType::create($data);
        return response()->json($type, 201);
    }

    public function updateType(Request $request, LeaveType $leaveType): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::LEAVE_MANAGE), 403);

        $data = $request->validate([
            'name'              => 'sometimes|string|max:100',
            'code'              => 'nullable|string|max:20',
            'days_per_year'     => 'sometimes|integer|min:0',
            'is_paid'           => 'boolean',
            'carry_forward'     => 'boolean',
            'max_carry_days'    => 'integer|min:0',
            'requires_approval' => 'boolean',
            'is_active'         => 'boolean',
            'counting_method'   => 'in:business_days,calendar_days',
            'working_days'      => 'nullable|array',
            'working_days.*'    => 'integer|between:1,7',
            'allow_half_day'    => 'boolean',
            'allow_scattered'   => 'boolean',
        ]);
        $leaveType->update($data);
        return response()->json($leaveType);
    }

    public function destroyType(LeaveType $leaveType): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::LEAVE_MANAGE), 403);

        $leaveType->delete();
        return response()->json(null, 204);
    }

    // ── Leave Balances ────────────────────────────────────────────────────────

    public function balances(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::LEAVE_VIEW), 403);

        $orgId = app('current.organization_id');
        $year  = $request->integer('year', now()->year);

        $balances = LeaveBalance::with(['employee', 'leaveType'])
            ->whereHas('employee', fn ($q) => $q->where('primary_organization_id', $orgId))
            ->where('year', $year)
            ->orderBy('employee_id')
            ->get()
            ->map(fn ($b) => [
                'id'            => $b->id,
                'employee_id'   => $b->employee_id,
                'employee_name' => optional($b->employee)->full_name,
                'leave_type'    => $b->leaveType?->name,
                'leave_type_id' => $b->leave_type_id,
                'year'          => $b->year,
                'entitled_days' => $b->entitled_days,
                'used_days'     => $b->used_days,
                'pending_days'  => $b->pending_days,
                'carried_days'  => $b->carried_days,
                'remaining'     => $b->entitled_days + $b->carried_days - $b->used_days - $b->pending_days,
            ]);

        return response()->json(['data' => $balances]);
    }

    /**
     * Initialize leave balances for all active employees in the org for a given year.
     * Uses days_per_year from each leave type.
     */
    public function initBalances(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::LEAVE_MANAGE), 403);

        $data  = $request->validate(['year' => 'required|integer|min:2020|max:2100']);
        $orgId = app('current.organization_id');
        $year  = $data['year'];

        $employees = Employee::where('primary_organization_id', $orgId)
            ->where('status', 'active')
            ->pluck('id');

        $types = LeaveType::where('organization_id', $orgId)
            ->where('is_active', true)
            ->get();

        $created = 0;
        foreach ($employees as $empId) {
            foreach ($types as $type) {
                $exists = LeaveBalance::where('employee_id', $empId)
                    ->where('leave_type_id', $type->id)
                    ->where('year', $year)
                    ->exists();
                if (! $exists) {
                    LeaveBalance::create([
                        'employee_id'   => $empId,
                        'leave_type_id' => $type->id,
                        'year'          => $year,
                        'entitled_days' => $type->days_per_year,
                        'used_days'     => 0,
                        'pending_days'  => 0,
                        'carried_days'  => 0,
                    ]);
                    $created++;
                }
            }
        }

        return response()->json(['message' => "Initialized {$created} balance records for {$year}."]);
    }

    public function updateBalance(Request $request, LeaveBalance $leaveBalance): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::LEAVE_MANAGE), 403);

        $data = $request->validate([
            'entitled_days' => 'sometimes|numeric|min:0',
            'carried_days'  => 'sometimes|numeric|min:0',
        ]);
        $leaveBalance->update($data);
        return response()->json($leaveBalance);
    }
}
