<?php

namespace App\Http\Controllers\Recruitment;

use App\Support\Storage\DocumentVault;
use App\Http\Controllers\Controller;
use App\Http\Requests\Recruitment\CreateCandidateRequest;
use App\Http\Resources\Recruitment\CandidateResource;
use App\Models\Recruitment\Candidate;
use App\Services\Recruitment\CandidateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CandidateController extends Controller
{
    public function __construct(private readonly CandidateService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Candidate::class);
        $candidates = $this->service->list(
            organizationId: app('current.organization_id'),
            filters: $request->only(['search', 'source', 'vacancy_id', 'status', 'date_from', 'date_to', 'archived']),
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
        return new CandidateResource(
            $candidate->load(['applications.vacancy', 'applications.currentStage', 'applications.stageHistory'])
        );
    }

    public function update(CreateCandidateRequest $request, Candidate $candidate): CandidateResource
    {
        $this->authorize('update', $candidate);
        $updated = $this->service->update($candidate, $request->validated());
        return new CandidateResource($updated);
    }

    public function destroy(Candidate $candidate): JsonResponse
    {
        $this->authorize('delete', $candidate);
        $candidate->delete();
        return response()->json(['message' => 'Candidate deleted.']);
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

    /**
     * Stream a candidate's CV to an authorised viewer.
     *
     * CVs used to be served straight off the storage symlink, so the URL alone
     * granted access to anyone who held it. Streaming through the app means
     * every download passes the same policy check as viewing the candidate,
     * and the file itself has no publicly reachable address.
     */
    public function downloadResume(Candidate $candidate): StreamedResponse
    {
        $this->authorize('view', $candidate);
        return $this->streamResume($candidate);
    }

    /**
     * Same file, reached by a signed link instead of a bearer token.
     *
     * The signature is the authorisation — Laravel's `signed` middleware has
     * already rejected anything tampered with or expired before we get here.
     * Global scopes are skipped deliberately: this route runs outside
     * `org.context`, so there is no current organisation to scope against, and
     * the signature already names one specific candidate.
     */
    public function downloadSignedResume(int $candidate): StreamedResponse
    {
        $model = Candidate::withoutGlobalScopes()->findOrFail($candidate);
        return $this->streamResume($model);
    }

    private function streamResume(Candidate $candidate): StreamedResponse
    {
        abort_if($candidate->resume_path === null, 404, 'No CV on file.');

        $disk = Storage::disk('candidate_docs');
        abort_unless($disk->exists($candidate->resume_path), 404, 'File not found on disk.');

        // The stored name is a random UUID; give the viewer something readable.
        $filename = Str::slug($candidate->full_name ?: 'candidate')
            . '-cv.' . pathinfo($candidate->resume_path, PATHINFO_EXTENSION);

        /*
         * Through the vault, which decrypts.
         *
         * This streamed the raw bytes. Every CV that arrived through the public
         * portal is sealed, so what a recruiter downloaded was the ciphertext —
         * a file beginning `KVAULT001:` that no reader can open. It went
         * unnoticed only because the CVs on file today all predate the portal
         * and happen to be plain.
         *
         * Worse, it was a trap: `documents:encrypt-existing` is in the runbook
         * and would have sealed those too, turning every CV download in the
         * system into a corrupt file at once. Unsealed input passes straight
         * through, so this handles both.
         */
        return DocumentVault::download(
            'candidate_docs',
            $candidate->resume_path,
            $filename,
            $disk->mimeType($candidate->resume_path) ?: 'application/octet-stream',
        );
    }

    public function bulkAction(Request $request): JsonResponse
    {
        $this->authorize('create', Candidate::class);
        $request->validate([
            'action'          => 'required|in:archive,unarchive,delete',
            'candidate_ids'   => 'required|array|min:1',
            'candidate_ids.*' => 'integer|exists:candidates,id',
        ]);

        $orgId      = app('current.organization_id');
        $action     = $request->input('action');
        $candidates = Candidate::where('organization_id', $orgId)
            ->whereIn('id', $request->input('candidate_ids'))
            ->get();

        $count = 0;
        foreach ($candidates as $c) {
            match ($action) {
                'archive'   => $c->update(['is_archived' => true,  'archived_at' => now()]),
                'unarchive' => $c->update(['is_archived' => false, 'archived_at' => null]),
                'delete'    => $c->delete(),
            };
            $count++;
        }

        return response()->json(['message' => "{$count} candidate(s) updated.", 'count' => $count]);
    }
}
