<?php

namespace App\Http\Controllers\Recruitment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Recruitment\CreateCandidateRequest;
use App\Http\Resources\Recruitment\CandidateResource;
use App\Models\Recruitment\Candidate;
use App\Services\Recruitment\CandidateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CandidateController extends Controller
{
    public function __construct(private readonly CandidateService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Candidate::class);
        $candidates = $this->service->list(
            organizationId: app('current.organization_id'),
            filters: $request->only(['search', 'source']),
            perPage: (int) $request->input('per_page', 20),
        );
        return CandidateResource::collection($candidates);
    }

    public function store(CreateCandidateRequest $request): CandidateResource
    {
        $candidate = $this->service->create(
            organizationId: app('current.organization_id'),
            data: $request->validated(),
        );
        return new CandidateResource($candidate);
    }

    public function show(Candidate $candidate): CandidateResource
    {
        $this->authorize('view', $candidate);
        return new CandidateResource($candidate->load(['applications.vacancy', 'applications.currentStage', 'applications.stageHistory']));
    }

    public function update(CreateCandidateRequest $request, Candidate $candidate): CandidateResource
    {
        $this->authorize('update', $candidate);
        $updated = $this->service->update($candidate, $request->validated());
        return new CandidateResource($updated);
    }

    public function uploadResume(Request $request, Candidate $candidate): CandidateResource
    {
        $this->authorize('update', $candidate);
        $request->validate([
            'resume' => 'required|file|mimes:pdf,doc,docx|max:5120',
        ]);
        $updated = $this->service->uploadResume($candidate, $request->file('resume'));
        return new CandidateResource($updated);
    }
}
