<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Controller;
use App\Http\Requests\Leave\ActionLeaveRequest;
use App\Http\Requests\Leave\SubmitLeaveRequest;
use App\Http\Resources\LeaveRequestResource;
use App\Models\LeaveType;
use App\Services\Leave\LeaveCalculatorService;
use App\Services\Leave\LeaveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LeaveController extends Controller
{
    public function __construct(
        private readonly LeaveService $service,
        private readonly LeaveCalculatorService $calculator,
        private readonly \App\Services\Task\LeaveHandoverService $handovers,
    ) {}

    /**
     * GET /api/v1/leave
     * List leave requests for the current organisation (managers/HR see all; employees see own).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $orgId = app('current.organization_id');

        $this->authorize('viewAny', \App\Models\LeaveRequest::class);

        $filters = $request->only(['status', 'employee_id']);

        // Employees only ever see their own requests.
        if (! $request->user()->isOrgHR($orgId) && ! $request->user()->isGroupAdmin()) {
            $filters['employee_id'] = $request->user()->employee?->id;
        }

        $items = $this->service->list($orgId, $filters, $request->integer('per_page', 20));

        return LeaveRequestResource::collection($items);
    }

    /**
     * POST /api/v1/leave
     * Submit a leave request for the authenticated employee.
     */
    public function store(SubmitLeaveRequest $request): LeaveRequestResource
    {
        $orgId     = app('current.organization_id');
        $employee  = $request->user()->employee;

        abort_if(! $employee, 422, 'User has no linked employee record.');

        $leave = $this->service->submit($employee->id, $orgId, $request->validated());

        return new LeaveRequestResource($leave);
    }

    /**
     * GET /api/v1/leave/{leaveRequest}
     */
    public function show(Request $request, int $leaveRequest): LeaveRequestResource
    {
        $orgId = app('current.organization_id');
        $leave  = \App\Models\LeaveRequest::withoutGlobalScope('organization')
            ->where('organization_id', $orgId)
            ->with(['leaveType', 'approvedBy'])
            ->findOrFail($leaveRequest);

        $this->authorize('view', $leave);

        return new LeaveRequestResource($leave);
    }

    /**
     * POST /api/v1/leave/{leaveRequest}/approve
     */
    public function approve(ActionLeaveRequest $request, int $leaveRequest): LeaveRequestResource
    {
        $this->authorize('action', \App\Models\LeaveRequest::class);

        $employee = $request->user()->employee;
        abort_if(! $employee, 422, 'Approver has no linked employee record.');

        $leave = $this->service->approve($leaveRequest, $employee->id);

        return new LeaveRequestResource($leave);
    }

    /**
     * POST /api/v1/leave/{leaveRequest}/reject
     */
    public function reject(ActionLeaveRequest $request, int $leaveRequest): LeaveRequestResource
    {
        $this->authorize('action', \App\Models\LeaveRequest::class);

        $employee = $request->user()->employee;
        abort_if(! $employee, 422, 'Approver has no linked employee record.');

        $reason = $request->validated('rejection_reason', '');
        $leave  = $this->service->reject($leaveRequest, $employee->id, $reason);

        return new LeaveRequestResource($leave);
    }

    /**
     * DELETE /api/v1/leave/{leaveRequest}
     * Cancel a pending request (employee's own).
     */
    public function destroy(Request $request, int $leaveRequest): JsonResponse
    {
        $employee = $request->user()->employee;
        abort_if(! $employee, 422, 'User has no linked employee record.');

        $this->service->cancel($leaveRequest, $employee->id);

        /*
         * Cancelled leave means nobody is away, so nobody is covering.
         *
         * Leaving the cover standing would put somebody else's name against
         * tasks their owner is sitting at their desk doing — and the owner's
         * own list would not show them.
         */
        $leave = \App\Models\LeaveRequest::find($leaveRequest);

        if ($leave) {
            $this->handovers->releaseAllFor($leave);
        }

        return response()->json(['message' => 'Leave request cancelled.']);
    }

    /**
     * GET /api/v1/leave/balance?year=2026
     */
    public function balance(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;

        if (! $employee) {
            return response()->json(['data' => [], 'year' => $request->integer('year', now()->year)]);
        }

        $year     = $request->integer('year', now()->year);
        $balances = $this->service->getBalances($employee->id, $year);

        return response()->json(['data' => $balances, 'year' => $year]);
    }

    /**
     * POST /api/v1/leave/calculate
     * Preview how many days a leave request would consume — without submitting it.
     */
    public function calculate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'leave_type_id'    => ['required', 'integer', 'exists:leave_types,id'],
            'from_date'        => ['nullable', 'date'],
            'to_date'          => ['nullable', 'date', 'after_or_equal:from_date'],
            'is_half_day'      => ['boolean'],
            'half_day_period'  => ['nullable', 'in:morning,afternoon'],
            'scattered_dates'  => ['nullable', 'array'],
            'scattered_dates.*'=> ['date'],
        ]);

        $orgId     = app('current.organization_id');
        $leaveType = LeaveType::findOrFail($data['leave_type_id']);
        $isHalfDay = (bool) ($data['is_half_day'] ?? false);
        $scattered = $data['scattered_dates'] ?? null;

        $days = $this->calculator->calculate(
            $leaveType,
            $orgId,
            $data['from_date'] ?? null,
            $data['to_date'] ?? null,
            $isHalfDay,
            $scattered,
        );

        $breakdown = $this->calculator->breakdown(
            $leaveType,
            $orgId,
            $data['from_date'] ?? null,
            $data['to_date'] ?? null,
            $isHalfDay,
            $scattered,
        );

        return response()->json([
            'days'             => $days,
            'counting_method'  => $leaveType->counting_method,
            'breakdown'        => $breakdown,
        ]);
    }
}
