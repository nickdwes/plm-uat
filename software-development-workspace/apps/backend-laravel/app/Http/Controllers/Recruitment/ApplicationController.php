<?php

namespace App\Http\Controllers\Recruitment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Recruitment\CreateApplicationRequest;
use App\Http\Requests\Recruitment\MoveApplicationStageRequest;
use App\Http\Resources\Recruitment\ApplicationResource;
use App\Models\Recruitment\Application;
use App\Models\Recruitment\Vacancy;
use App\Services\Recruitment\ApplicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ApplicationController extends Controller
{
    public function __construct(private readonly ApplicationService $service) {}

    public function index(Request $request, Vacancy $vacancy): AnonymousResourceCollection
    {
        $this->authorize('viewAny', \App\Models\Recruitment\Vacancy::class);
        $applications = $this->service->listForVacancy(
            vacancy: $vacancy,
            filters: $request->only(['status', 'stage_id']),
            perPage: (int) $request->input('per_page', 50),
        );
        return ApplicationResource::collection($applications);
    }

    public function store(CreateApplicationRequest $request, Vacancy $vacancy): ApplicationResource
    {
        $application = $this->service->create($vacancy, array_merge(
            $request->validated(),
            ['created_by' => $request->user()->id],
        ));
        return new ApplicationResource($application);
    }

    public function show(Application $application): ApplicationResource
    {
        $this->authorize('view', $application);
        return new ApplicationResource(
            $application->load(['candidate', 'currentStage', 'stageHistory.toStage', 'stageHistory.fromStage', 'stageHistory.movedBy', 'vacancy'])
        );
    }

    public function moveStage(MoveApplicationStageRequest $request, Application $application): ApplicationResource
    {
        $this->authorize('moveStage', $application);
        $updated = $this->service->moveStage(
            application: $application,
            toStageId: $request->integer('stage_id'),
            movedBy: $request->user()->id,
            notes: $request->input('notes'),
        );
        return new ApplicationResource($updated);
    }

    public function bulkAction(Request $request): JsonResponse
    {
        $this->authorize('create', Application::class);
        $request->validate([
            'action'          => 'required|in:reject,shortlist',
            'application_ids' => 'required|array|min:1',
            'application_ids.*' => 'integer|exists:applications,id',
        ]);
        $count = $this->service->bulkAction(
            applicationIds: $request->input('application_ids'),
            action: $request->input('action'),
            movedBy: $request->user()->id,
        );
        return response()->json(['message' => "{$count} application(s) updated.", 'count' => $count]);
    }
}
