<?php

namespace App\Http\Controllers\Recruitment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Recruitment\CreateWorkflowRequest;
use App\Http\Requests\Recruitment\UpdateWorkflowRequest;
use App\Http\Resources\Recruitment\WorkflowResource;
use App\Models\Recruitment\RecruitmentWorkflow;
use App\Services\Recruitment\WorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WorkflowController extends Controller
{
    public function __construct(private readonly WorkflowService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', \App\Models\Recruitment\Vacancy::class);
        $workflows = $this->service->list(
            organizationId: app('current.organization_id'),
            perPage: (int) $request->input('per_page', 50),
        );
        return WorkflowResource::collection($workflows);
    }

    public function store(CreateWorkflowRequest $request): WorkflowResource
    {
        $workflow = $this->service->create(
            organizationId: app('current.organization_id'),
            data: $request->validated(),
        );
        return new WorkflowResource($workflow);
    }

    public function show(RecruitmentWorkflow $workflow): WorkflowResource
    {
        $this->authorize('viewAny', \App\Models\Recruitment\Vacancy::class);
        return new WorkflowResource($workflow->load('stages'));
    }

    public function update(UpdateWorkflowRequest $request, RecruitmentWorkflow $workflow): WorkflowResource
    {
        $updated = $this->service->update($workflow, $request->validated());
        return new WorkflowResource($updated);
    }

    public function stagesUpdate(Request $request, RecruitmentWorkflow $workflow): WorkflowResource
    {
        $this->authorize('create', \App\Models\Recruitment\Vacancy::class);
        $request->validate([
            'stages'              => 'required|array',
            'stages.*.name'       => 'required|string|max:100',
            'stages.*.stage_type' => 'required|in:applied,screening,interview,shortlisted,offer,hired,rejected,pre_onboarding',
        ]);
        $this->service->syncStages($workflow, $request->input('stages'));
        return new WorkflowResource($workflow->fresh(['stages']));
    }

    public function destroy(RecruitmentWorkflow $workflow): JsonResponse
    {
        $this->authorize('create', \App\Models\Recruitment\Vacancy::class);
        $this->service->delete($workflow);
        return response()->json(['message' => 'Workflow deleted.']);
    }
}
