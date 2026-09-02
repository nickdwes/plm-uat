<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use App\Http\Requests\Training\CreateTrainingItemRequest;
use App\Http\Resources\Training\TrainingItemResource;
use App\Models\Training\TrainingItem;
use App\Services\Training\TrainingItemService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TrainingItemController extends Controller
{
    public function __construct(private readonly TrainingItemService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', TrainingItem::class);

        $items = $this->service->list(
            organizationId: app('current.organization_id'),
            filters: $request->only(['is_active', 'type', 'delivery', 'search']),
            perPage: (int) $request->input('per_page', 20),
        );

        return TrainingItemResource::collection($items);
    }

    private const OEM_COMPLIANCE_KEYS = ['oem_compliance_required', 'oem_compliance_headcount', 'oem_compliance_notes'];

    public function store(CreateTrainingItemRequest $request): TrainingItemResource
    {
        $item = $this->service->create(
            organizationId: app('current.organization_id'),
            data: $request->safe()->except(['competency_ids', 'product_ids', ...self::OEM_COMPLIANCE_KEYS]),
            competencyIds: $request->input('competency_ids', []),
            productIds: $request->input('product_ids', []),
            oemCompliance: $this->oemComplianceInput($request),
        );

        return new TrainingItemResource($item);
    }

    public function show(TrainingItem $trainingItem): TrainingItemResource
    {
        $this->authorize('view', $trainingItem);

        return new TrainingItemResource(
            $trainingItem->load(['competencies', 'certifyingBody', 'vendorPartner', 'products', 'requiredByVendors'])
        );
    }

    public function update(CreateTrainingItemRequest $request, TrainingItem $trainingItem): TrainingItemResource
    {
        $this->authorize('update', $trainingItem);

        $updated = $this->service->update(
            item: $trainingItem,
            data: $request->safe()->except(['competency_ids', 'product_ids', ...self::OEM_COMPLIANCE_KEYS]),
            competencyIds: $request->has('competency_ids') ? $request->input('competency_ids') : null,
            productIds: $request->has('product_ids') ? $request->input('product_ids') : null,
            oemCompliance: $this->oemComplianceInput($request),
        );

        return new TrainingItemResource($updated);
    }

    private function oemComplianceInput(Request $request): array
    {
        return [
            'required'  => $request->boolean('oem_compliance_required'),
            'headcount' => $request->input('oem_compliance_headcount'),
            'notes'     => $request->input('oem_compliance_notes'),
        ];
    }

    public function destroy(TrainingItem $trainingItem): JsonResponse
    {
        $this->authorize('delete', $trainingItem);
        $this->service->destroy($trainingItem);

        return response()->json(null, 204);
    }

    public function stats(): JsonResponse
    {
        $this->authorize('viewAny', TrainingItem::class);

        $total   = TrainingItem::count();
        $active  = TrainingItem::where('is_active', true)->count();
        $enrolled = \App\Models\Training\EmployeeTrainingRecord::count();
        $completed = \App\Models\Training\EmployeeTrainingRecord::where('status', 'completed')->count();

        return response()->json(compact('total', 'active', 'enrolled', 'completed'));
    }

    /**
     * Budgeted spend, scoped to active items — a retired course isn't part
     * of what the organisation is currently planning to spend. Grouped by
     * currency rather than blindly summed: nothing enforces one currency
     * across the catalog, and silently adding KES to USD would just be
     * wrong, not merely imprecise.
     */
    public function budgetSummary(): JsonResponse
    {
        $this->authorize('viewAny', TrainingItem::class);

        $rows = TrainingItem::where('organization_id', app('current.organization_id'))
            ->where('is_active', true)
            ->selectRaw(
                'cost_currency,
                 COUNT(*) as total_items,
                 COUNT(*) FILTER (WHERE is_budgeted) as budgeted_items,
                 COALESCE(SUM(budget_amount) FILTER (WHERE is_budgeted), 0) as total_budgeted_amount,
                 COALESCE(SUM(estimated_cost), 0) as total_estimated_cost'
            )
            ->groupBy('cost_currency')
            ->orderBy('cost_currency')
            ->get();

        return response()->json(['data' => $rows]);
    }
}
