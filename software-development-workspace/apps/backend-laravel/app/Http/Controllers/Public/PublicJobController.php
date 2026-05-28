<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Recruitment\Application;
use App\Models\Recruitment\Candidate;
use App\Models\Recruitment\Vacancy;
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
            ->whereNull('deleted_at');

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
            ->firstOrFail();

        $validated = $request->validate([
            'first_name'    => 'required|string|max:100',
            'last_name'     => 'required|string|max:100',
            'email'         => 'required|email:rfc,dns|max:255',
            'phone'         => 'nullable|string|max:30',
            'location'      => 'nullable|string|max:255',
            'linkedin_url'  => 'nullable|url|max:500',
            'cover_letter'  => 'nullable|string|max:8000',
            'cv'            => 'required|file|mimes:pdf,doc,docx|max:5120',
            'attachment'    => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:5120',
        ]);

        // Store CV with a safe random name
        $cvExtension = $request->file('cv')->getClientOriginalExtension();
        $cvPath = $request->file('cv')->storeAs(
            'resumes/' . $v->uuid,
            Str::uuid() . '.' . $cvExtension,
            'public'
        );

        $attachmentPath = null;
        if ($request->hasFile('attachment')) {
            $ext  = $request->file('attachment')->getClientOriginalExtension();
            $attachmentPath = $request->file('attachment')->storeAs(
                'attachments/' . $v->uuid,
                Str::uuid() . '.' . $ext,
                'public'
            );
        }

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

        // Guard: no duplicate application for same vacancy
        if (Application::where('vacancy_id', $v->id)->where('candidate_id', $candidate->id)->exists()) {
            return response()->json(['message' => 'You have already applied for this position.'], 422);
        }

        // First stage of the workflow
        $firstStage = $v->workflow?->stages()?->orderBy('order_index')->first();

        $application = Application::create([
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
            'reference' => 'APP-' . str_pad($application->id, 6, '0', STR_PAD_LEFT),
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
