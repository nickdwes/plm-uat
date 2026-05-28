<?php

namespace App\Http\Controllers\Recruitment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Recruitment\CreateVacancyTemplateRequest;
use App\Http\Resources\Recruitment\VacancyTemplateResource;
use App\Models\Recruitment\VacancyTemplate;
use App\Services\Recruitment\VacancyTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class VacancyTemplateController extends Controller
{
    public function __construct(private readonly VacancyTemplateService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', \App\Models\Recruitment\Vacancy::class);
        $templates = $this->service->list(
            organizationId: app('current.organization_id'),
            filters: $request->only(['search', 'is_active']),
            perPage: (int) $request->input('per_page', 20),
        );
        return VacancyTemplateResource::collection($templates);
    }

    public function store(CreateVacancyTemplateRequest $request): VacancyTemplateResource
    {
        $template = $this->service->create(
            organizationId: app('current.organization_id'),
            data: $request->validated(),
        );
        return new VacancyTemplateResource($template->load(['workflow.stages', 'department', 'designation']));
    }

    public function show(VacancyTemplate $vacancyTemplate): VacancyTemplateResource
    {
        $this->authorize('viewAny', \App\Models\Recruitment\Vacancy::class);
        return new VacancyTemplateResource($vacancyTemplate->load(['workflow.stages', 'department', 'designation']));
    }

    public function update(CreateVacancyTemplateRequest $request, VacancyTemplate $vacancyTemplate): VacancyTemplateResource
    {
        $updated = $this->service->update($vacancyTemplate, $request->validated());
        return new VacancyTemplateResource($updated);
    }

    public function destroy(VacancyTemplate $vacancyTemplate): JsonResponse
    {
        $this->authorize('create', \App\Models\Recruitment\Vacancy::class);
        $this->service->delete($vacancyTemplate);
        return response()->json(['message' => 'Template deleted.']);
    }

    public function duplicate(VacancyTemplate $vacancyTemplate): VacancyTemplateResource
    {
        $this->authorize('create', \App\Models\Recruitment\Vacancy::class);
        $copy = $this->service->duplicate($vacancyTemplate);
        return new VacancyTemplateResource($copy->load(['workflow.stages', 'department', 'designation']));
    }
}
