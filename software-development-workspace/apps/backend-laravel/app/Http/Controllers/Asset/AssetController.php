<?php

namespace App\Http\Controllers\Asset;

use App\Http\Controllers\Controller;
use App\Http\Requests\Asset\AssignAssetRequest;
use App\Http\Requests\Asset\StoreAssetRequest;
use App\Http\Requests\Asset\UpdateAssetRequest;
use App\Http\Resources\Asset\AssetResource;
use App\Models\Asset\Asset;
use App\Models\Asset\AssetRequest;
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

        $filters = $request->only([
            'status', 'category', 'department_id', 'assigned', 'assigned_to',
            'search', 'warranty_expired', 'maintenance_due',
        ]);

        /*
         * "Mine" is the caller's own employee id, resolved here.
         *
         * The client cannot send it — it knows the signed-in user, not which
         * employee record that maps to — so asking it to would mean an extra
         * round trip and a second place that gets the mapping wrong.
         */
        if ($request->boolean('mine')) {
            $filters['assigned_to'] = $request->user()->employeeId() ?? -1;
        }

        $assets = $this->service->list(
            filters: $filters,
            perPage: (int) $request->input('per_page', 20),
        );

        return AssetResource::collection($assets);
    }

    public function show(Asset $asset): AssetResource
    {
        $this->authorize('view', $asset);

        // Events come with the asset because the detail page draws the
        // lifecycle timeline on arrival; a second round trip for something
        // always shown is a round trip nobody needed.
        return new AssetResource($asset->load([
            'holder', 'department', 'events.employee', 'events.vendor',
        ]));
    }

    /**
     * The numbers across the top of the Assets page.
     *
     * Counted from the same rows the tabs list, so a card and the list beneath
     * it can never tell different stories — the failure mode of every dashboard
     * assembled from its own queries.
     */
    public function summary(): JsonResponse
    {
        $this->authorize('viewAny', Asset::class);

        $base = fn () => Asset::query()->where('status', '!=', Asset::STATUS_RETIRED);

        return response()->json([
            'totals' => [
                'assigned'  => (clone $base())->whereNotNull('assigned_to')->count(),
                'available' => (clone $base())->where('status', Asset::STATUS_AVAILABLE)->count(),
                'pending_requests' => AssetRequest::where('status', AssetRequest::STATUS_PENDING)->count(),
                'under_repair' => (clone $base())->where('status', Asset::STATUS_MAINTENANCE)->count(),
            ],

            'details' => [
                // The subscription work made this one answerable at all.
                'licences_expiring' => Asset::expiringSoon()->count(),
                'due_for_return'    => (clone $base())->whereNotNull('assigned_to')
                    ->whereNotNull('expires_on')->count(),
                'maintenance_due'   => (clone $base())->whereNotNull('next_maintenance')
                    ->whereDate('next_maintenance', '<=', now()->toDateString())->count(),
                /*
                 * What the register is worth, retired items excluded.
                 *
                 * Purchase value, not book value — there is no depreciation in
                 * this system, and presenting a purchase total as if it were
                 * current worth is the kind of number finance quotes back at
                 * you a year later.
                 */
                'purchase_value'    => (float) (clone $base())->sum('purchase_value'),
            ],

            // The three charts. Counted here rather than in the client so the
            // donut and the table behind it cannot disagree.
            'by_category'   => (clone $base())->selectRaw('category as label, count(*) as count')
                ->groupBy('category')->orderByDesc('count')->get(),
            'by_department' => (clone $base())->join('departments', 'departments.id', '=', 'assets.department_id')
                ->selectRaw('departments.name as label, count(*) as count')
                ->groupBy('departments.name')->orderByDesc('count')->get(),
            'by_status'     => Asset::selectRaw('status as label, count(*) as count')
                ->groupBy('status')->get(),
        ]);
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
