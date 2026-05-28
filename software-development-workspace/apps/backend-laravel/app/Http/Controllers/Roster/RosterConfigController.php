<?php

namespace App\Http\Controllers\Roster;

use App\Http\Controllers\Controller;
use App\Http\Requests\Roster\StoreShiftGroupRequest;
use App\Http\Requests\Roster\StoreShiftTypeRequest;
use App\Http\Requests\Roster\UpsertRosterConfigRequest;
use App\Http\Resources\Roster\RosterConfigResource;
use App\Http\Resources\Roster\ShiftGroupResource;
use App\Http\Resources\Roster\ShiftTypeResource;
use App\Models\Roster\RosterShiftGroup;
use App\Models\Roster\RosterShiftType;
use App\Services\Roster\RosterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RosterConfigController extends Controller
{
    public function __construct(private readonly RosterService $service) {}

    // ── General Configuration ─────────────────────────────────────────────────

    /**
     * GET /api/v1/roster/config
     */
    public function showConfig(Request $request): RosterConfigResource
    {
        abort_unless(
            $request->user()->isOrgHR(app('current.organization_id')),
            403,
            'Only HR administrators can view roster configuration.'
        );

        $config = $this->service->getConfig(app('current.organization_id'));

        return new RosterConfigResource($config);
    }

    /**
     * PUT /api/v1/roster/config
     */
    public function updateConfig(UpsertRosterConfigRequest $request): RosterConfigResource
    {
        abort_unless(
            $request->user()->isOrgHR(app('current.organization_id')),
            403,
            'Only HR administrators can update roster configuration.'
        );

        $config = $this->service->updateConfig(
            app('current.organization_id'),
            $request->validated()
        );

        return new RosterConfigResource($config);
    }

    // ── Shift Groups ──────────────────────────────────────────────────────────

    /**
     * GET /api/v1/roster/shift-groups
     */
    public function indexShiftGroups(Request $request): AnonymousResourceCollection
    {
        abort_unless(
            $request->user()->isManagerIn(app('current.organization_id')),
            403
        );

        $groups = $this->service->listShiftGroups(app('current.organization_id'));

        return ShiftGroupResource::collection($groups);
    }

    /**
     * POST /api/v1/roster/shift-groups
     */
    public function storeShiftGroup(StoreShiftGroupRequest $request): ShiftGroupResource
    {
        abort_unless(
            $request->user()->isOrgHR(app('current.organization_id')),
            403
        );

        $group = $this->service->storeShiftGroup(
            app('current.organization_id'),
            $request->validated()
        );

        return new ShiftGroupResource($group);
    }

    /**
     * PUT /api/v1/roster/shift-groups/{shiftGroup}
     */
    public function updateShiftGroup(StoreShiftGroupRequest $request, RosterShiftGroup $shiftGroup): ShiftGroupResource
    {
        abort_unless(
            $request->user()->isOrgHR(app('current.organization_id')),
            403
        );

        $this->ensureBelongsToOrg($shiftGroup->organization_id);

        $group = $this->service->updateShiftGroup($shiftGroup, $request->validated());

        return new ShiftGroupResource($group);
    }

    /**
     * DELETE /api/v1/roster/shift-groups/{shiftGroup}
     */
    public function destroyShiftGroup(Request $request, RosterShiftGroup $shiftGroup): JsonResponse
    {
        abort_unless(
            $request->user()->isOrgHR(app('current.organization_id')),
            403
        );

        $this->ensureBelongsToOrg($shiftGroup->organization_id);

        $this->service->destroyShiftGroup($shiftGroup);

        return response()->json(['message' => 'Shift group deleted.']);
    }

    // ── Shift Types ───────────────────────────────────────────────────────────

    /**
     * GET /api/v1/roster/shift-types
     */
    public function indexShiftTypes(Request $request): AnonymousResourceCollection
    {
        abort_unless(
            $request->user()->isManagerIn(app('current.organization_id')),
            403
        );

        $types = $this->service->listShiftTypes(
            app('current.organization_id'),
            $request->only(['shift_group_id', 'is_active', 'search'])
        );

        return ShiftTypeResource::collection($types);
    }

    /**
     * POST /api/v1/roster/shift-types
     */
    public function storeShiftType(StoreShiftTypeRequest $request): JsonResponse
    {
        abort_unless(
            $request->user()->isOrgHR(app('current.organization_id')),
            403
        );

        $type = $this->service->storeShiftType(
            app('current.organization_id'),
            $request->validated()
        );

        return (new ShiftTypeResource($type->load('shiftGroup')))->response()->setStatusCode(201);
    }

    /**
     * PUT /api/v1/roster/shift-types/{shiftType}
     */
    public function updateShiftType(StoreShiftTypeRequest $request, RosterShiftType $shiftType): ShiftTypeResource
    {
        abort_unless(
            $request->user()->isOrgHR(app('current.organization_id')),
            403
        );

        $this->ensureBelongsToOrg($shiftType->organization_id);

        $type = $this->service->updateShiftType($shiftType, $request->validated());

        return new ShiftTypeResource($type);
    }

    /**
     * DELETE /api/v1/roster/shift-types/{shiftType}
     */
    public function destroyShiftType(Request $request, RosterShiftType $shiftType): JsonResponse
    {
        abort_unless(
            $request->user()->isOrgHR(app('current.organization_id')),
            403
        );

        $this->ensureBelongsToOrg($shiftType->organization_id);

        $this->service->destroyShiftType($shiftType);

        return response()->json(['message' => 'Shift type deleted.']);
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
