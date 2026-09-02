<?php

namespace App\Http\Controllers\Roster;

use App\Support\Authorization\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Roster\AssignShiftRequest;
use App\Http\Requests\Roster\BulkAssignShiftRequest;
use App\Http\Requests\Roster\StoreRosterScheduleRequest;
use App\Http\Resources\Roster\RosterAssignmentResource;
use App\Http\Resources\Roster\RosterScheduleResource;
use App\Models\Roster\RosterAssignment;
use App\Models\Roster\RosterSchedule;
use App\Services\Roster\RosterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RosterScheduleController extends Controller
{
    public function __construct(private readonly RosterService $service) {}

    // ── Schedules ─────────────────────────────────────────────────────────────

    /**
     * GET /api/v1/roster
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless(
            $request->user()->hasPermission(Permission::ROSTER_VIEW),
            403
        );

        $schedules = $this->service->listSchedules(
            app('current.organization_id'),
            $request->only(['status']),
            $request->integer('per_page', 15)
        );

        return RosterScheduleResource::collection($schedules);
    }

    /**
     * POST /api/v1/roster
     */
    public function store(StoreRosterScheduleRequest $request): JsonResponse
    {
        abort_unless(
            $request->user()->hasPermission(Permission::ROSTER_PUBLISH),
            403
        );

        $schedule = $this->service->storeSchedule(
            app('current.organization_id'),
            $request->user()->id,
            $request->validated()
        );

        return (new RosterScheduleResource($schedule->load('creator')))->response()->setStatusCode(201);
    }

    /**
     * GET /api/v1/roster/{schedule}
     */
    public function show(Request $request, RosterSchedule $schedule): RosterScheduleResource
    {
        abort_unless(
            $request->user()->hasPermission(Permission::ROSTER_VIEW),
            403
        );

        $this->ensureBelongsToOrg($schedule->organization_id);

        return new RosterScheduleResource($schedule->load('creator'));
    }

    /**
     * PUT /api/v1/roster/{schedule}
     */
    public function update(StoreRosterScheduleRequest $request, RosterSchedule $schedule): RosterScheduleResource
    {
        abort_unless(
            $request->user()->hasPermission(Permission::ROSTER_PUBLISH),
            403
        );

        $this->ensureBelongsToOrg($schedule->organization_id);

        $updated = $this->service->updateSchedule($schedule, $request->validated());

        return new RosterScheduleResource($updated);
    }

    /**
     * POST /api/v1/roster/{schedule}/publish
     */
    public function publish(Request $request, RosterSchedule $schedule): RosterScheduleResource
    {
        abort_unless(
            $request->user()->hasPermission(Permission::ROSTER_PUBLISH),
            403
        );

        $this->ensureBelongsToOrg($schedule->organization_id);

        $published = $this->service->publishSchedule($schedule);

        return new RosterScheduleResource($published);
    }

    /**
     * DELETE /api/v1/roster/{schedule}
     */
    public function destroy(Request $request, RosterSchedule $schedule): JsonResponse
    {
        abort_unless(
            $request->user()->hasPermission(Permission::ROSTER_PUBLISH),
            403
        );

        $this->ensureBelongsToOrg($schedule->organization_id);

        $this->service->destroySchedule($schedule);

        return response()->json(['message' => 'Schedule deleted.']);
    }

    // ── Assignments ───────────────────────────────────────────────────────────

    /**
     * GET /api/v1/roster/{schedule}/assignments
     * Managers see all; employees see only their own.
     */
    public function assignments(Request $request, RosterSchedule $schedule): AnonymousResourceCollection
    {
        $orgId = app('current.organization_id');
        $this->ensureBelongsToOrg($schedule->organization_id);

        $filters = $request->only(['date']);

        // Non-managers are scoped to their own employee record.
        if (! $request->user()->hasPermission(Permission::ROSTER_VIEW)) {
            $employee = $request->user()->employee;
            abort_if(! $employee, 403, 'No employee record linked to this account.');
            $filters['employee_id'] = $employee->id;
        } elseif ($request->filled('employee_id')) {
            $filters['employee_id'] = $request->integer('employee_id');
        }

        $assignments = $this->service->getAssignments($schedule, $filters);

        return RosterAssignmentResource::collection($assignments);
    }

    /**
     * POST /api/v1/roster/{schedule}/assignments
     */
    public function assign(AssignShiftRequest $request, RosterSchedule $schedule): JsonResponse
    {
        abort_unless(
            $request->user()->hasPermission(Permission::ROSTER_MANAGE),
            403
        );

        $this->ensureBelongsToOrg($schedule->organization_id);

        $assignment = $this->service->assignShift($schedule, $request->validated());

        return (new RosterAssignmentResource($assignment->load(['employee', 'shiftType'])))->response()->setStatusCode(201);
    }

    /**
     * POST /api/v1/roster/{schedule}/assignments/bulk
     */
    public function bulkAssign(BulkAssignShiftRequest $request, RosterSchedule $schedule): JsonResponse
    {
        abort_unless(
            $request->user()->hasPermission(Permission::ROSTER_MANAGE),
            403
        );

        $this->ensureBelongsToOrg($schedule->organization_id);

        $result = $this->service->bulkAssign($schedule, $request->validated());

        return response()->json([
            'message' => "Bulk assignment complete: {$result['created']} created, {$result['skipped']} skipped (conflicts).",
            'created' => $result['created'],
            'skipped' => $result['skipped'],
        ]);
    }

    /**
     * DELETE /api/v1/roster/{schedule}/assignments/{assignment}
     */
    public function removeAssignment(
        Request $request,
        RosterSchedule $schedule,
        RosterAssignment $assignment
    ): JsonResponse {
        abort_unless(
            $request->user()->hasPermission(Permission::ROSTER_MANAGE),
            403
        );

        $this->ensureBelongsToOrg($schedule->organization_id);

        abort_unless(
            $assignment->schedule_id === $schedule->id,
            404,
            'Assignment does not belong to this schedule.'
        );

        $this->service->removeAssignment($assignment);

        return response()->json(['message' => 'Assignment removed.']);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function ensureBelongsToOrg(int $resourceOrgId): void
    {
        abort_unless(
            $resourceOrgId === app('current.organization_id'),
            404
        );
    }
}
