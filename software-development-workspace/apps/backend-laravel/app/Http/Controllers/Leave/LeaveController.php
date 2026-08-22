<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Controller;
use App\Http\Requests\Leave\ActionLeaveRequest;
use App\Http\Requests\Leave\SubmitLeaveRequest;
use App\Http\Resources\LeaveRequestResource;
use App\Models\LeaveType;
use App\Services\Leave\LeaveCalculatorService;
use App\Services\Leave\LeaveService;
use App\Services\Leave\LeaveSummary;
use App\Services\Leave\LeaveVisibility;
use App\Services\Leave\WeekAvailability;
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
     *
     * One listing, three widths. An HR officer sees the organisation, a team
     * leader sees their reports, and everybody else sees themselves —
     * decided by LeaveVisibility so the rows, the counts and the per-request
     * checks cannot disagree about who the viewer is.
     *
     * A caller may ask for a narrower scope than they hold (`?scope=own`, for
     * the My Leaves tab) but never a wider one: the request is a preference,
     * the permission is the ceiling.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $orgId = app('current.organization_id');

        $this->authorize('viewAny', \App\Models\LeaveRequest::class);

        $filters = $request->only(['status', 'employee_id', 'search', 'month', 'leave_type_id']);

        $scope = $this->scopeFor($request, $orgId);

        $filters['employee_ids'] = LeaveVisibility::employeeIds($scope, $request->user(), $orgId);

        $items = $this->service->list($orgId, $filters, $request->integer('per_page', 20));

        return LeaveRequestResource::collection($items)
            ->additional(['meta' => ['scope' => $scope]]);
    }

    /**
     * GET /api/v1/leave/summary
     *
     * The figures along the top of the screen, counted over the same
     * population the listing returns.
     */
    public function summary(Request $request, LeaveSummary $summary): JsonResponse
    {
        $orgId = app('current.organization_id');

        $this->authorize('viewAny', \App\Models\LeaveRequest::class);

        $scope = $this->scopeFor($request, $orgId);

        return response()->json([
            'data' => $summary->for(
                $orgId,
                LeaveVisibility::employeeIds($scope, $request->user(), $orgId),
            ) + ['scope' => $scope],
        ]);
    }

    /**
     * GET /api/v1/leave/availability
     *
     * The same population read by person rather than by request — of the
     * people I am responsible for, who is here this week.
     */
    public function availability(Request $request, WeekAvailability $availability): JsonResponse
    {
        $orgId = app('current.organization_id');

        $this->authorize('viewAny', \App\Models\LeaveRequest::class);

        $scope = $this->scopeFor($request, $orgId);

        /*
         * Only somebody entitled to see past themselves. A one-row
         * availability table telling you that you are available is not a
         * screen worth serving.
         */
        abort_if($scope === LeaveVisibility::OWN, 403, 'This view is for managers and HR.');

        $week = $request->filled('week')
            ? \Illuminate\Support\Carbon::parse($request->string('week')->toString())
            : null;

        return response()->json([
            'data' => $availability->for(
                $orgId,
                LeaveVisibility::employeeIds($scope, $request->user(), $orgId),
                $week,
                ['search' => $request->string('search')->toString() ?: null],
            ) + ['scope' => $scope],
        ]);
    }

    /**
     * The scope actually applied: what was asked for, capped by what is held.
     *
     * Ranked so a narrower request is honoured and a wider one is quietly
     * brought back down rather than refused — asking for more than you have
     * is what a shared front-end does when it does not yet know who you are,
     * not an attack worth a 403.
     */
    private function scopeFor(Request $request, ?int $orgId): string
    {
        $held = LeaveVisibility::scopeFor($request->user(), $orgId);

        $asked = $request->string('scope')->toString();

        $rank = [LeaveVisibility::OWN => 0, LeaveVisibility::TEAM => 1, LeaveVisibility::ALL => 2];

        if (! isset($rank[$asked])) {
            return $held;
        }

        return $rank[$asked] <= $rank[$held] ? $asked : $held;
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
