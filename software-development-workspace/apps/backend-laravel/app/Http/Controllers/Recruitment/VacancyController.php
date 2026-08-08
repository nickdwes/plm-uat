<?php

namespace App\Http\Controllers\Recruitment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Recruitment\CreateVacancyRequest;
use App\Http\Resources\Recruitment\VacancyResource;
use App\Models\Recruitment\Vacancy;
use App\Services\Recruitment\VacancyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class VacancyController extends Controller
{
    public function __construct(private readonly VacancyService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Vacancy::class);
        $vacancies = $this->service->list(
            organizationId: app('current.organization_id'),
            filters: $request->only(['status', 'department_id', 'search']),
            perPage: (int) $request->input('per_page', 20),
        );
        return VacancyResource::collection($vacancies);
    }

    public function store(CreateVacancyRequest $request): VacancyResource
    {
        /*
         * Stated here as well as on the form request.
         *
         * A rule that lives only in a request object is a rule nobody reading
         * the controller can see, and the next endpoint added beside this one
         * gets written without it.
         */
        $this->authorize('create', Vacancy::class);

        $vacancy = $this->service->create(
            organizationId: app('current.organization_id'),
            data: $request->validated(),
        );
        return new VacancyResource($vacancy);
    }

    public function show(Vacancy $vacancy): VacancyResource
    {
        $this->authorize('view', $vacancy);
        return new VacancyResource($vacancy->load(['workflow.stages', 'department', 'designation', 'hiringManager']));
    }

    public function update(CreateVacancyRequest $request, Vacancy $vacancy): VacancyResource
    {
        $this->authorize('update', $vacancy);
        $updated = $this->service->update($vacancy, $request->validated());
        return new VacancyResource($updated);
    }

    public function close(Vacancy $vacancy): VacancyResource
    {
        $this->authorize('update', $vacancy);
        return new VacancyResource($this->service->close($vacancy));
    }

    /** Dashboard KPIs */
    public function stats(): JsonResponse
    {
        $this->authorize('viewAny', Vacancy::class);
        $orgId = app('current.organization_id');

        $open        = Vacancy::where('status', 'open')->count();
        $draft       = Vacancy::where('status', 'draft')->count();
        $applications = \App\Models\Recruitment\Application::whereHas('vacancy',
            fn($q) => $q->where('organization_id', $orgId)
        )->count();
        $shortlisted = \App\Models\Recruitment\Application::whereHas('vacancy',
            fn($q) => $q->where('organization_id', $orgId)
        )->where('status', 'shortlisted')->count();
        $hired       = \App\Models\Recruitment\Application::whereHas('vacancy',
            fn($q) => $q->where('organization_id', $orgId)
        )->where('status', 'hired')->count();

        return response()->json(compact('open', 'draft', 'applications', 'shortlisted', 'hired'));
    }
}
