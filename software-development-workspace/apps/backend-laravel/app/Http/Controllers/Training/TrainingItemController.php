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

    public function store(CreateTrainingItemRequest $request): TrainingItemResource
    {
        $item = $this->service->create(
            organizationId: app('current.organization_id'),
            data: $request->safe()->except(['competency_ids']),
            competencyIds: $request->input('competency_ids', []),
        );

        return new TrainingItemResource($item);
    }

    public function show(TrainingItem $trainingItem): TrainingItemResource
    {
        $this->authorize('view', $trainingItem);

        return new TrainingItemResource(
            $trainingItem->load(['competencies'])
        );
    }

    public function update(CreateTrainingItemRequest $request, TrainingItem $trainingItem): TrainingItemResource
    {
        $this->authorize('update', $trainingItem);

        $updated = $this->service->update(
            item: $trainingItem,
            data: $request->safe()->except(['competency_ids']),
            competencyIds: $request->has('competency_ids') ? $request->input('competency_ids') : null,
        );

        return new TrainingItemResource($updated);
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
}
