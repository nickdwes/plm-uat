<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use App\Http\Requests\Training\CreateTrainingBudgetRequestRequest;
use App\Http\Resources\Training\TrainingBudgetRequestResource;
use App\Models\Training\TrainingBudgetRequest;
use App\Models\Training\TrainingBudgetSetting;
use App\Services\Training\TrainingBudgetRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TrainingBudgetRequestController extends Controller
{
    public function __construct(private readonly TrainingBudgetRequestService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', TrainingBudgetRequest::class);

        return TrainingBudgetRequestResource::collection(
            $this->service->list(app('current.organization_id'), $request->only(['status', 'mode', 'training_item_id']))
        );
    }

    public function myPending(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', TrainingBudgetRequest::class);

        return TrainingBudgetRequestResource::collection(
            $this->service->myPending(app('current.organization_id'), $request->user())
        );
    }

    public function store(CreateTrainingBudgetRequestRequest $request): TrainingBudgetRequestResource
    {
        $budgetRequest = $this->service->create(
            organizationId: app('current.organization_id'),
            submittedByUserId: $request->user()->id,
            data: $request->safe()->except(['items']),
            items: $request->input('items'),
        );

        return new TrainingBudgetRequestResource($budgetRequest);
    }

    public function show(TrainingBudgetRequest $budgetRequest): TrainingBudgetRequestResource
    {
        $this->authorize('view', $budgetRequest);

        return new TrainingBudgetRequestResource(
            $budgetRequest->load(['items.employee', 'items.trainingItem', 'trainingItem', 'employee', 'manager', 'financeDecidedBy', 'submittedBy'])
        );
    }

    public function approveManager(Request $request, TrainingBudgetRequest $budgetRequest): TrainingBudgetRequestResource
    {
        $this->authorize('approveAsManager', $budgetRequest);

        return new TrainingBudgetRequestResource(
            $this->service->approveAsManager($budgetRequest, $request->input('notes'))
        );
    }

    public function approveFinance(Request $request, TrainingBudgetRequest $budgetRequest): TrainingBudgetRequestResource
    {
        $this->authorize('approveAsFinance', $budgetRequest);

        return new TrainingBudgetRequestResource(
            $this->service->approveAsFinance($budgetRequest, $request->user(), $request->input('notes'))
        );
    }

    public function reject(Request $request, TrainingBudgetRequest $budgetRequest): TrainingBudgetRequestResource
    {
        $data = $request->validate(['reason' => 'required|string|max:1000']);

        // Whichever stage the request is actually sitting at decides who is
        // allowed to reject it — the same actor rules as approving that stage.
        $stage = $budgetRequest->status === 'pending_finance' ? 'finance' : 'manager';
        $this->authorize($stage === 'finance' ? 'approveAsFinance' : 'approveAsManager', $budgetRequest);

        return new TrainingBudgetRequestResource(
            $this->service->reject($budgetRequest, $stage, $data['reason'])
        );
    }

    // ── Approval threshold setting ──────────────────────────────────────────

    public function showSettings(): JsonResponse
    {
        $this->authorize('viewAny', TrainingBudgetRequest::class);

        $settings = TrainingBudgetSetting::forOrganization(app('current.organization_id'));

        return response()->json(['data' => [
            'approval_threshold_amount'   => $settings->approval_threshold_amount,
            'approval_threshold_currency' => $settings->approval_threshold_currency,
        ]]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $this->authorize('create', TrainingBudgetRequest::class);

        $data = $request->validate([
            'approval_threshold_amount'   => 'nullable|numeric|min:0',
            'approval_threshold_currency' => 'nullable|string|size:3',
        ]);

        $settings = TrainingBudgetSetting::forOrganization(app('current.organization_id'));
        $settings->update($data);

        return response()->json(['data' => [
            'approval_threshold_amount'   => $settings->approval_threshold_amount,
            'approval_threshold_currency' => $settings->approval_threshold_currency,
        ]]);
    }
}
