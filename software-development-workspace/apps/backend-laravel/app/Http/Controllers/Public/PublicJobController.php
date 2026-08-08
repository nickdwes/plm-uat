<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Recruitment\Application;
use App\Models\Recruitment\Candidate;
use App\Models\Recruitment\Vacancy;
use App\Support\Storage\DocumentVault;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PublicJobController extends Controller
{
    /**
     * List all currently open vacancies across all organisations.
     * Safe: returns only public-safe fields, no internal IDs.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Vacancy::withoutGlobalScope('organization')
            ->with([
                'department'    => fn ($q) => $q->withoutGlobalScope('organization'),
                'designation'   => fn ($q) => $q->withoutGlobalScope('organization'),
                'organization',
            ])
            ->where('status', 'open')
            ->whereNull('deleted_at')
            // A switched-off tenant should not still be advertising, and
            // certainly should not still be collecting CVs into itself.
            ->whereHas('organization', fn ($q) => $q->where('is_active', true));

        if ($search = $request->input('search')) {
            $search = strip_tags($search);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('job_title', 'ilike', "%{$search}%")
                  ->orWhere('location', 'ilike', "%{$search}%");
            });
        }

        if ($dept = $request->input('department')) {
            $query->whereHas('department', fn ($q) => $q->where('name', 'ilike', "%{$dept}%"));
        }

        $paginator = $query->orderByDesc('created_at')->paginate(20);

        return response()->json([
            'data' => $paginator->map(fn (Vacancy $v) => $this->publicSummary($v)),
            'meta' => [
                'total'        => $paginator->total(),
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * Return detail of a single open vacancy by UUID.
     */
    public function show(string $uuid): JsonResponse
    {
        $v = Vacancy::withoutGlobalScope('organization')
            ->with([
                'department'  => fn ($q) => $q->withoutGlobalScope('organization'),
                'designation' => fn ($q) => $q->withoutGlobalScope('organization'),
                'organization',
            ])
            ->where('uuid', $uuid)
            ->where('status', 'open')
            ->whereNull('deleted_at')
            ->whereHas('organization', fn ($q) => $q->where('is_active', true))
            ->firstOrFail();

        return response()->json(['data' => $this->publicDetail($v)]);
    }

    /**
     * Submit a job application.
     * Creates/updates candidate record and opens an Application.
     */
    public function apply(Request $request, string $uuid): JsonResponse
    {
        $v = Vacancy::withoutGlobalScope('organization')
            ->with(['workflow.stages'])
            ->where('uuid', $uuid)
            ->where('status', 'open')
            ->whereNull('deleted_at')
            ->whereHas('organization', fn ($q) => $q->where('is_active', true))
            ->firstOrFail();

        /*
         * Past its closing date is closed, whatever the status column says.
         *
         * A vacancy nobody remembered to close keeps accepting applications for
         * ever otherwise — and every one of them is a CV taken for a role that
         * is not being filled.
         */
        abort_if(
            $v->closing_date !== null && $v->closing_date->endOfDay()->isPast(),
            404,
        );

        $validated = $request->validate([
            'first_name'    => 'required|string|max:100',
            'last_name'     => 'required|string|max:100',
            /*
             * `rfc` without `dns`. A DNS lookup on an anonymous endpoint is a
             * slow path the caller chooses — a domain with a black-holed
             * nameserver holds a worker open for the resolver timeout, and
             * they are free to submit as many as the throttle allows.
             */
            'email'         => 'required|email:rfc|max:255',
            'phone'         => 'nullable|string|max:30',
            'location'      => 'nullable|string|max:255',
            'linkedin_url'  => 'nullable|url|max:500',
            'cover_letter'  => 'nullable|string|max:8000',
            /*
             * Both rules, deliberately. `mimes` checks the extension against a
             * guessed type; `mimetypes` checks what the bytes actually are. A
             * renamed executable satisfies the first on its own.
             */
            'cv' => [
                'required', 'file', 'max:5120', 'mimes:pdf,doc,docx',
                'mimetypes:application/pdf,application/msword,'
                    . 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ],
            'attachment' => [
                'nullable', 'file', 'max:5120', 'mimes:pdf,doc,docx,jpg,jpeg,png',
                'mimetypes:application/pdf,application/msword,'
                    . 'application/vnd.openxmlformats-officedocument.wordprocessingml.document,'
                    . 'image/jpeg,image/png',
            ],
        ]);

        /*
         * Sealed before it reaches storage, like every other document class.
         *
         * `candidate_docs` is listed in config/documents.php as an encrypted
         * disk, and this endpoint wrote raw bytes straight past the vault — so
         * CVs were the only class held in the clear, arriving through the one
         * door anonymous strangers can reach. A CV carries a name, an address,
         * a phone number and an employment history; it is the most sensitive
         * thing this system accepts from outside.
         */
        $cvPath = DocumentVault::store($request->file('cv'), 'candidate_docs', 'resumes/' . $v->uuid);

        $attachmentPath = $request->hasFile('attachment')
            ? DocumentVault::store($request->file('attachment'), 'candidate_docs', 'attachments/' . $v->uuid)
            : null;

        // Find or create the candidate (unique per org + email)
        $candidate = Candidate::firstOrCreate(
            ['organization_id' => $v->organization_id, 'email' => strtolower($validated['email'])],
            [
                'first_name'   => strip_tags($validated['first_name']),
                'last_name'    => strip_tags($validated['last_name']),
                'phone'        => $validated['phone'] ?? null,
                'resume_path'  => $cvPath,
                'source'       => 'Website',
                'keywords'     => [],
                'social_links' => array_filter(['linkedin' => $validated['linkedin_url'] ?? null]),
            ]
        );

        // Always update resume to the latest submission
        if (! $candidate->wasRecentlyCreated) {
            $candidate->update(['resume_path' => $cvPath]);
        }

        /*
         * Applying twice updates the CV and says the same thing as applying
         * once.
         *
         * The old reply — "You have already applied" — let anybody holding a
         * vacancy link test whether a given email had applied to it, one
         * address at a time. Answering identically either way removes the
         * oracle, and it is the better outcome anyway: somebody re-applying
         * usually means to send a newer CV, which is exactly what happens.
         */
        $existing = Application::where('vacancy_id', $v->id)
            ->where('candidate_id', $candidate->id)->first();

        if ($existing) {
            return response()->json([
                'message'   => 'Application submitted successfully. We will be in touch shortly.',
                'reference' => $existing->reference,
            ], 201);
        }

        // First stage of the workflow
        $firstStage = $v->workflow?->stages()?->orderBy('order_index')->first();

        $application = Application::create([
            // Random rather than the primary key: `APP-000042` told an
            // applicant how many applications the whole portal had taken.
            'reference'        => 'APP-' . strtoupper(Str::random(10)),
            'vacancy_id'       => $v->id,
            'candidate_id'     => $candidate->id,
            'current_stage_id' => $firstStage?->id,
            'status'           => 'active',
            'form_data'        => array_filter([
                'cover_letter'   => $validated['cover_letter'] ?? null,
                'location'       => $validated['location'] ?? null,
                'attachment_path'=> $attachmentPath,
            ]),
            'applied_at'       => now(),
        ]);

        return response()->json([
            'message'   => 'Application submitted successfully. We will be in touch shortly.',
            'reference' => $application->reference,
        ], 201);
    }

    // ── Private helpers ───────────────────────────────────────────────────

    private function orgBranding(?Organization $org): array
    {
        if (! $org) {
            return ['name' => null, 'logo_url' => null, 'primary_color' => '#004C97'];
        }

        $logoUrl = null;
        if ($org->logo_path) {
            $logoUrl = Storage::disk('public')->exists($org->logo_path)
                ? Storage::disk('public')->url($org->logo_path)
                : null;
        }

        return [
            'name'          => $org->name,
            'logo_url'      => $logoUrl,
            'primary_color' => $org->primary_color ?? '#004C97',
        ];
    }

    private function publicSummary(Vacancy $v): array
    {
        return [
            'uuid'          => $v->uuid,
            'title'         => $v->job_title,
            'name'          => $v->name,
            'location'      => $v->location,
            'department'    => $v->department?->name,
            'open_positions'=> $v->open_positions,
            'closing_date'  => $v->closing_date?->toDateString(),
            'posted_at'     => $v->created_at?->toDateString(),
            'organization'  => $this->orgBranding($v->organization),
        ];
    }

    private function publicDetail(Vacancy $v): array
    {
        return [
            'uuid'                    => $v->uuid,
            'title'                   => $v->job_title,
            'name'                    => $v->name,
            'location'                => $v->location,
            'department'              => $v->department?->name,
            'designation'             => $v->designation?->title,
            'open_positions'          => $v->open_positions,
            'closing_date'            => $v->closing_date?->toDateString(),
            'posted_at'               => $v->created_at?->toDateString(),
            'description'             => $v->description,
            'application_form_schema' => $v->application_form_schema ?? [],
            'organization'            => $this->orgBranding($v->organization),
        ];
    }
}
