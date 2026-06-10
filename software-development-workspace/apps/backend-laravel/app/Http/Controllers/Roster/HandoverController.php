<?php

namespace App\Http\Controllers\Roster;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Roster\HandoverReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HandoverController extends Controller
{
    // GET /api/v1/roster/handovers
    public function index(Request $request): JsonResponse
    {
        $orgId = app('current.organization_id');

        $query = HandoverReport::where('organization_id', $orgId)
            ->with(['shiftType', 'outgoingEmployee', 'incomingEmployee', 'acknowledgedBy'])
            ->orderByDesc('report_date')
            ->orderByDesc('created_at');

        if ($request->filled('schedule_id')) {
            $query->where('schedule_id', $request->integer('schedule_id'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('report_date', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('report_date', '<=', $request->input('date_to'));
        }

        // Non-managers see only their own reports
        $user = $request->user();
        if (! $user->isManagerIn($orgId) && ! $user->isOrgHR($orgId)) {
            $employee = Employee::where('user_id', $user->id)
                ->where('primary_organization_id', $orgId)
                ->first();
            if ($employee) {
                $query->where(function ($q) use ($employee) {
                    $q->where('outgoing_employee_id', $employee->id)
                      ->orWhere('incoming_employee_id', $employee->id);
                });
            }
        }

        $reports = $query->paginate($request->integer('per_page', 20));

        return response()->json([
            'data' => $reports->items(),
            'meta' => [
                'total'        => $reports->total(),
                'current_page' => $reports->currentPage(),
                'last_page'    => $reports->lastPage(),
            ],
        ]);
    }

    // POST /api/v1/roster/handovers
    public function store(Request $request): JsonResponse
    {
        $orgId = app('current.organization_id');

        $data = $request->validate([
            'schedule_id'          => ['nullable', 'integer', 'exists:roster_schedules,id'],
            'shift_type_id'        => ['nullable', 'integer', 'exists:roster_shift_types,id'],
            'outgoing_employee_id' => ['required', 'integer', 'exists:employees,id'],
            'incoming_employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'report_date'          => ['required', 'date'],
            'shift_start'          => ['nullable', 'date_format:H:i'],
            'shift_end'            => ['nullable', 'date_format:H:i'],
            'tasks_completed'      => ['nullable', 'string', 'max:5000'],
            'ongoing_issues'       => ['nullable', 'string', 'max:3000'],
            'tasks_pending'        => ['nullable', 'string', 'max:3000'],
            'equipment_notes'      => ['nullable', 'string', 'max:2000'],
            'general_notes'        => ['nullable', 'string', 'max:2000'],
        ]);

        $report = HandoverReport::create(array_merge($data, ['organization_id' => $orgId]));
        $report->load(['shiftType', 'outgoingEmployee', 'incomingEmployee']);

        return response()->json(['data' => $this->formatReport($report)], 201);
    }

    // PUT /api/v1/roster/handovers/{handover}
    public function update(Request $request, int $handover): JsonResponse
    {
        $orgId  = app('current.organization_id');
        $report = HandoverReport::where('organization_id', $orgId)->findOrFail($handover);

        if ($report->acknowledged_at) {
            return response()->json(['message' => 'Acknowledged reports cannot be edited.'], 422);
        }

        $data = $request->validate([
            'incoming_employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'shift_start'          => ['nullable', 'date_format:H:i'],
            'shift_end'            => ['nullable', 'date_format:H:i'],
            'tasks_completed'      => ['nullable', 'string', 'max:5000'],
            'ongoing_issues'       => ['nullable', 'string', 'max:3000'],
            'tasks_pending'        => ['nullable', 'string', 'max:3000'],
            'equipment_notes'      => ['nullable', 'string', 'max:2000'],
            'general_notes'        => ['nullable', 'string', 'max:2000'],
        ]);

        $report->update($data);
        $report->load(['shiftType', 'outgoingEmployee', 'incomingEmployee', 'acknowledgedBy']);

        return response()->json(['data' => $this->formatReport($report)]);
    }

    // POST /api/v1/roster/handovers/{handover}/acknowledge
    public function acknowledge(Request $request, int $handover): JsonResponse
    {
        $orgId  = app('current.organization_id');
        $report = HandoverReport::where('organization_id', $orgId)->findOrFail($handover);

        if ($report->acknowledged_at) {
            return response()->json(['message' => 'Already acknowledged.'], 422);
        }

        $user     = $request->user();
        $employee = Employee::where('user_id', $user->id)
            ->where('primary_organization_id', $orgId)
            ->first();

        $report->update([
            'acknowledged_at'    => now(),
            'acknowledged_by_id' => $employee?->id,
        ]);

        $report->load(['shiftType', 'outgoingEmployee', 'incomingEmployee', 'acknowledgedBy']);

        return response()->json(['data' => $this->formatReport($report)]);
    }

    private function formatReport(HandoverReport $r): array
    {
        return [
            'id'                  => $r->id,
            'schedule_id'         => $r->schedule_id,
            'report_date'         => $r->report_date?->toDateString(),
            'shift_start'         => $r->shift_start,
            'shift_end'           => $r->shift_end,
            'shift_type'          => $r->shiftType ? [
                'id'    => $r->shiftType->id,
                'name'  => $r->shiftType->name,
                'color' => $r->shiftType->color ?? '#3B82F6',
            ] : null,
            'outgoing_employee'   => $r->outgoingEmployee ? [
                'id'        => $r->outgoingEmployee->id,
                'full_name' => $r->outgoingEmployee->full_name,
            ] : null,
            'incoming_employee'   => $r->incomingEmployee ? [
                'id'        => $r->incomingEmployee->id,
                'full_name' => $r->incomingEmployee->full_name,
            ] : null,
            'tasks_completed'     => $r->tasks_completed,
            'ongoing_issues'      => $r->ongoing_issues,
            'tasks_pending'       => $r->tasks_pending,
            'equipment_notes'     => $r->equipment_notes,
            'general_notes'       => $r->general_notes,
            'acknowledged_at'     => $r->acknowledged_at?->toISOString(),
            'acknowledged_by'     => $r->acknowledgedBy ? [
                'id'        => $r->acknowledgedBy->id,
                'full_name' => $r->acknowledgedBy->full_name,
            ] : null,
            'created_at'          => $r->created_at?->toISOString(),
        ];
    }
}
