<?php

namespace App\Http\Controllers\Asset;

use App\Http\Controllers\Controller;
use App\Http\Requests\Asset\AssignAssetRequest;
use App\Http\Requests\Asset\StoreAssetRequest;
use App\Http\Requests\Asset\UpdateAssetRequest;
use App\Http\Resources\Asset\AssetResource;
use App\Models\Asset\Asset;
use App\Models\Employee;
use App\Services\Asset\AssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AssetController extends Controller
{
    public function __construct(private readonly AssetService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Asset::class);

        $assets = $this->service->list(
            filters: $request->only([
                'status', 'category', 'department_id', 'assigned', 'assigned_to',
                'search', 'warranty_expired', 'maintenance_due',
            ]),
            perPage: (int) $request->input('per_page', 20),
        );

        return AssetResource::collection($assets);
    }

    public function show(Asset $asset): AssetResource
    {
        $this->authorize('view', $asset);

        return new AssetResource($asset->load(['holder', 'department']));
    }

    public function store(StoreAssetRequest $request): AssetResource
    {
        $asset = $this->service->create(
            organizationId: app('current.organization_id'),
            data: $request->validated(),
        );

        return new AssetResource($asset->load(['holder', 'department']));
    }

    public function update(UpdateAssetRequest $request, Asset $asset): AssetResource
    {
        return new AssetResource($this->service->update($asset, $request->validated()));
    }

    public function destroy(Asset $asset): JsonResponse
    {
        $this->authorize('delete', $asset);

        // Soft delete — an asset that has been in someone's hands is part of
        // the custody record, and a hard delete would take that with it.
        $asset->delete();

        return response()->json(['message' => 'Asset retired from the register.']);
    }

    /** Hand the asset to an employee. */
    public function assign(AssignAssetRequest $request, Asset $asset): AssetResource
    {
        $employee = Employee::findOrFail($request->integer('employee_id'));

        // The employee must be in the same tenant. Without this, a valid
        // employee id from another organization would pass validation — the
        // exists rule does not know about tenancy.
        abort_unless(
            (int) $employee->primary_organization_id === (int) app('current.organization_id'),
            422,
            'That employee is not in the current organization.'
        );

        return new AssetResource(
            $this->service->assign($asset, $employee, $request->input('assigned_date'))
        );
    }

    /** Take the asset back. */
    public function release(Request $request, Asset $asset): AssetResource
    {
        $this->authorize('assign', $asset);

        $status = $request->input('status', Asset::STATUS_AVAILABLE);

        abort_unless(
            in_array($status, [Asset::STATUS_AVAILABLE, Asset::STATUS_MAINTENANCE, Asset::STATUS_RETIRED], true),
            422,
            'An asset can only be released to available, maintenance or retired.'
        );

        return new AssetResource($this->service->release($asset, $status));
    }
}
