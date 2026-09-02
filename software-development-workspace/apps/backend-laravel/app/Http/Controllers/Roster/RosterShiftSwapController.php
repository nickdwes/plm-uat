<?php

namespace App\Http\Controllers\Roster;

use App\Support\Authorization\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Roster\StoreShiftSwapRequest;
use App\Http\Resources\Roster\ShiftSwapResource;
use App\Models\Roster\RosterShiftSwapRequest as SwapModel;
use App\Services\Roster\RosterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RosterShiftSwapController extends Controller
{
    public function __construct(private readonly RosterService $service) {}

    /**
     * GET /api/v1/roster/swaps
     * Managers see all swaps; employees see only their own.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $orgId = app('current.organization_id');

        $filters = $request->only(['status']);

        if (! $request->user()->hasPermission(Permission::ROSTER_VIEW)) {
            $employee = $request->user()->employee;
            abort_if(! $employee, 403, 'No employee record linked to this account.');
            $filters['employee_id'] = $employee->id;
        } elseif ($request->filled('employee_id')) {
            $filters['employee_id'] = $request->integer('employee_id');
        }

        $swaps = $this->service->listSwapRequests(
            $orgId,
            $filters,
            $request->integer('per_page', 20)
        );

        return ShiftSwapResource::collection($swaps);
    }

    /**
     * POST /api/v1/roster/swaps
     * An employee creates a swap request for one of their own shifts.
     */
    public function store(StoreShiftSwapRequest $request): ShiftSwapResource
    {
        $orgId    = app('current.organization_id');
        $employee = $request->user()->employee;

        abort_if(! $employee, 422, 'No employee record linked to this account.');

        // Swap feature must be enabled in org config.
        $config = $this->service->getConfig($orgId);
        abort_unless($config->swap_shifts_enabled, 403, 'Shift swaps are not enabled for this organisation.');

        $swap = $this->service->createSwapRequest(
            $orgId,
            $employee->id,
            $request->validated()
        );

        return (new ShiftSwapResource($swap->load([
            'requester',
            'requestedEmployee',
            'requesterAssignment.shiftType',
            'requestedAssignment.shiftType',
        ])))->response()->setStatusCode(201);
    }

    /**
     * POST /api/v1/roster/swaps/{swap}/respond
     * The requested employee accepts or rejects the swap.
     */
    public function respond(Request $request, SwapModel $swap): ShiftSwapResource
    {
        $request->validate([
            'accept' => ['required', 'boolean'],
            'notes'  => ['nullable', 'string', 'max:500'],
        ]);

        $employee = $request->user()->employee;
        abort_if(! $employee, 422, 'No employee record linked to this account.');

        $updated = $this->service->respondToSwapRequest(
            $swap,
            $employee->id,
            (bool) $request->boolean('accept'),
            $request->input('notes')
        );

        return new ShiftSwapResource($updated->load([
            'requester',
            'requestedEmployee',
            'requesterAssignment.shiftType',
            'requestedAssignment.shiftType',
        ]));
    }

    /**
     * POST /api/v1/roster/swaps/{swap}/approve
     * A manager/HR approves an accepted swap (if manager approval is required).
     */
    public function approve(Request $request, SwapModel $swap): ShiftSwapResource
    {
        $orgId = app('current.organization_id');

        abort_unless(
            $request->user()->hasPermission(Permission::ROSTER_MANAGE),
            403,
            'Only managers or HR can approve shift swaps.'
        );

        $config = $this->service->getConfig($orgId);
        abort_unless(
            $config->manager_approval_required,
            422,
            'Manager approval is not required for this organisation.'
        );

        $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $employee = $request->user()->employee;
        abort_if(! $employee, 422, 'No employee record linked to this account.');

        $updated = $this->service->approveSwapRequest(
            $swap,
            $employee->id,
            $request->input('notes')
        );

        return new ShiftSwapResource($updated->load([
            'requester',
            'requestedEmployee',
            'requesterAssignment.shiftType',
            'requestedAssignment.shiftType',
            'approvedByEmployee',
        ]));
    }

    /**
     * DELETE /api/v1/roster/swaps/{swap}
     * The original requester cancels a pending swap.
     */
    public function cancel(Request $request, SwapModel $swap): JsonResponse
    {
        $employee = $request->user()->employee;
        abort_if(! $employee, 422, 'No employee record linked to this account.');

        $this->service->cancelSwapRequest($swap, $employee->id);

        return response()->json(['message' => 'Swap request cancelled.']);
    }
}
