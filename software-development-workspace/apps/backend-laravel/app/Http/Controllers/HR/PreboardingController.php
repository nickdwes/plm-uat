<?php

namespace App\Http\Controllers\HR;

use App\Support\Storage\DocumentVault;
use App\Models\Preboarding\PreboardingRecord;
use App\Http\Controllers\Controller;
use App\Mail\PreboardingLinkMail;
use App\Models\Organization;
use App\Models\Preboarding\PreboardingAttachment;
use App\Models\Preboarding\PreboardingReference;
use App\Services\HR\PreboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

class PreboardingController extends Controller
{
    public function __construct(private PreboardingService $service) {}

    private function orgId(Request $request): int
    {
        return (int) app('current.organization_id');
    }

    // ── Records ───────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $this->guardPermission($request);
        $records = $this->service->list($this->orgId($request), $request->only(['search', 'status', 'per_page', 'page']));
        return response()->json([
            'data' => $records->items(),
            'meta' => [
                'total'        => $records->total(),
                'last_page'    => $records->lastPage(),
                'current_page' => $records->currentPage(),
                'per_page'     => $records->perPage(),
            ],
        ]);
    }

    /**
     * The five counts across the top of the onboarding queue.
     *
     * Onboarding states, not the requisition ones the mock inherited: there is
     * no approval chain here and nothing to hold. What somebody reading this
     * page can act on is a submission waiting on them, and somebody whose start
     * date has arrived with their details still unfinished.
     */
    public function summary(Request $request): JsonResponse
    {
        $this->guardPermission($request);
        $orgId = $this->orgId($request);
        $scope = fn () => PreboardingRecord::where('organization_id', $orgId);

        return response()->json([
            // Invited, not yet opened.
            'awaiting_submission' => $scope()->where('status', 'pending')->count(),
            // Filling it in.
            'in_progress' => $scope()->where('status', 'in_progress')->count(),
            // Back with HR — the actionable one.
            'submitted'   => $scope()->where('status', 'submitted')->count(),

            /*
             * Their first day has arrived or passed and they are still not on
             * the system. The one number with a deadline attached.
             */
            'overdue' => $scope()
                ->whereNotIn('status', ['converted', 'completed'])
                ->whereNotNull('expected_start_date')
                ->whereDate('expected_start_date', '<=', now()->toDateString())
                ->count(),

            'completed_this_month' => $scope()
                ->whereIn('status', ['converted', 'completed'])
                ->whereBetween('updated_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->count(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->guardPermission($request);
        $data = $request->validate([
            'first_name'             => 'required|string|max:100',
            'last_name'              => 'required|string|max:100',
            'email'                  => 'required|email|max:255',
            'phone'                  => 'nullable|string|max:30',
            'position'               => 'nullable|string|max:150',
            'employee_type'          => 'nullable|string|in:permanent,contract,intern,probation',
            'expected_start_date'    => 'nullable|date',
            'candidate_id'           => 'nullable|integer',
            'medical_cover_eligible' => 'nullable|boolean',
            'link_expiry_days'       => 'nullable|integer|min:1|max:30',
            'enabled_sections'       => 'nullable|array',
            'enabled_sections.*'     => 'string',
            'notes'                  => 'nullable|string|max:1000',
        ]);

        $record = $this->service->create($this->orgId($request), $data, Auth::id());
        return response()->json($record, 201);
    }

    public function show(Request $request, int $preboarding): JsonResponse
    {
        $record = $this->service->get($preboarding);
        $this->authorizeOrg($record, $request);
        return response()->json($record);
    }

    public function update(Request $request, int $preboarding): JsonResponse
    {
        $record = PreboardingRecord::findOrFail($preboarding);
        $this->authorizeOrg($record, $request);

        $data = $request->validate([
            'first_name'          => 'sometimes|string|max:100',
            'last_name'           => 'sometimes|string|max:100',
            'email'               => 'sometimes|email|max:255',
            'phone'               => 'nullable|string|max:30',
            'position'            => 'nullable|string|max:150',
            'expected_start_date' => 'nullable|date',
            'notes'               => 'nullable|string|max:1000',
        ]);

        return response()->json($this->service->update($record, $data));
    }

    public function destroy(Request $request, int $preboarding): JsonResponse
    {
        $record = PreboardingRecord::findOrFail($preboarding);
        $this->authorizeOrg($record, $request);
        $this->service->delete($record);
        return response()->json(['message' => 'Deleted.']);
    }

    // ── Welcome / Announcement ────────────────────────────────────────────

    public function sendWelcome(Request $request, int $preboarding): JsonResponse
    {
        $record = PreboardingRecord::findOrFail($preboarding);
        $this->authorizeOrg($record, $request);
        return response()->json($this->service->markWelcomeSent($record));
    }

    public function sendAnnouncement(Request $request, int $preboarding): JsonResponse
    {
        $record = PreboardingRecord::findOrFail($preboarding);
        $this->authorizeOrg($record, $request);
        return response()->json($this->service->markAnnouncementSent($record));
    }

    // ── Sections ──────────────────────────────────────────────────────────

    public function updateSection(Request $request, int $preboarding, string $section): JsonResponse
    {
        $record = PreboardingRecord::findOrFail($preboarding);
        $this->authorizeOrg($record, $request);

        if (!in_array($section, PreboardingService::SECTIONS)) {
            return response()->json(['message' => 'Unknown section.'], 422);
        }

        $data = $request->validate(['data' => 'required|array']);
        return response()->json($this->service->updateSection($record, $section, $data['data']));
    }

    public function approveSection(Request $request, int $preboarding, string $section): JsonResponse
    {
        $record = PreboardingRecord::findOrFail($preboarding);
        $this->authorizeOrg($record, $request);

        $validated = $request->validate(['hr_notes' => 'nullable|string|max:500']);
        return response()->json(
            $this->service->approveSection($record, $section, Auth::id(), $validated['hr_notes'] ?? null)
        );
    }

    public function sectionNotApplicable(Request $request, int $preboarding, string $section): JsonResponse
    {
        $record = PreboardingRecord::findOrFail($preboarding);
        $this->authorizeOrg($record, $request);
        return response()->json($this->service->markSectionNotApplicable($record, $section));
    }

    // ── References ────────────────────────────────────────────────────────

    public function storeReference(Request $request, int $preboarding): JsonResponse
    {
        $record = PreboardingRecord::findOrFail($preboarding);
        $this->authorizeOrg($record, $request);

        $data = $request->validate([
            'referee_name'      => 'required|string|max:150',
            'referee_email'     => 'required|email|max:255',
            'referee_company'   => 'nullable|string|max:150',
            'referee_job_title' => 'nullable|string|max:150',
            'relationship'      => 'nullable|string|max:100',
        ]);

        return response()->json($this->service->addReference($record, $data), 201);
    }

    public function approveReference(Request $request, int $preboarding, int $reference): JsonResponse
    {
        $record = PreboardingRecord::findOrFail($preboarding);
        $this->authorizeOrg($record, $request);
        $ref = PreboardingReference::where('preboarding_id', $preboarding)->findOrFail($reference);
        return response()->json($this->service->approveReference($ref));
    }

    public function inviteReference(Request $request, int $preboarding, int $reference): JsonResponse
    {
        $record = PreboardingRecord::findOrFail($preboarding);
        $this->authorizeOrg($record, $request);
        $ref = PreboardingReference::where('preboarding_id', $preboarding)->findOrFail($reference);
        return response()->json($this->service->inviteReference($ref));
    }

    public function destroyReference(Request $request, int $preboarding, int $reference): JsonResponse
    {
        $record = PreboardingRecord::findOrFail($preboarding);
        $this->authorizeOrg($record, $request);
        $ref = PreboardingReference::where('preboarding_id', $preboarding)->findOrFail($reference);
        $this->service->deleteReference($ref);
        return response()->json(['message' => 'Deleted.']);
    }

    // ── Attachments ───────────────────────────────────────────────────────

    public function storeAttachment(Request $request, int $preboarding): JsonResponse
    {
        $record = PreboardingRecord::findOrFail($preboarding);
        $this->authorizeOrg($record, $request);

        $request->validate([
            'file'     => 'required|file|mimes:pdf,jpg,jpeg,png,webp,doc,docx|max:10240',
            'category' => ['required', 'string', 'regex:/^[a-z0-9_]+$/'],
        ]);

        $att = $this->service->storeAttachment(
            $record,
            $request->file('file'),
            $request->input('category'),
            Auth::id()
        );

        return response()->json($att, 201);
    }

    public function downloadAttachment(Request $request, int $preboarding, int $attachment): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $record = PreboardingRecord::findOrFail($preboarding);
        $this->authorizeOrg($record, $request);

        $att = PreboardingAttachment::where('preboarding_id', $preboarding)->findOrFail($attachment);

        abort_unless($att->fileExists(), 404, 'File not found on disk.');

        // Through the vault, which decrypts, and which serves the readable
        // name from the database now that the key is a UUID.
        return DocumentVault::download(
            'preboarding_docs', $att->storage_path, $att->original_name,
            $att->mime_type ?? 'application/octet-stream',
        );
    }

    public function destroyAttachment(Request $request, int $preboarding, int $attachment): JsonResponse
    {
        $record = PreboardingRecord::findOrFail($preboarding);
        $this->authorizeOrg($record, $request);
        $att = PreboardingAttachment::where('preboarding_id', $preboarding)->findOrFail($attachment);
        $this->service->deleteAttachment($att);
        return response()->json(['message' => 'Deleted.']);
    }

    // ── Convert to Employee ───────────────────────────────────────────────

    public function convert(Request $request, int $preboarding): JsonResponse
    {
        $record = PreboardingRecord::findOrFail($preboarding);
        $this->authorizeOrg($record, $request);

        $data = $request->validate(['employee_id' => 'required|integer|exists:employees,id']);
        return response()->json($this->service->markConverted($record, $data['employee_id']));
    }

    // ── Link management ───────────────────────────────────────────────────

    /**
     * Regenerate the secure access token, resetting its expiry.
     * Used when the original link has expired or was not received.
     */
    public function resendLink(Request $request, int $preboarding): JsonResponse
    {
        $record = PreboardingRecord::findOrFail($preboarding);
        $this->authorizeOrg($record, $request);
        return response()->json($this->service->resendLink($record));
    }

    /**
     * Send (or re-send) the form link to the candidate's email address.
     */
    public function sendLinkEmail(Request $request, int $preboarding): JsonResponse
    {
        $record = PreboardingRecord::findOrFail($preboarding);
        $this->authorizeOrg($record, $request);

        if (!$record->token) {
            return response()->json(['message' => 'No link generated yet. Regenerate first.'], 422);
        }

        $orgName = Organization::find($record->organization_id)?->name ?? 'HR Portal';

        Mail::to($record->email, trim($record->first_name . ' ' . $record->last_name))
            ->send(new PreboardingLinkMail($record, $orgName));

        return response()->json(['message' => 'Email sent to ' . $record->email]);
    }

    /**
     * Update the configurable form settings (enabled sections, expiry, medical eligibility).
     */
    public function updateFormConfig(Request $request, int $preboarding): JsonResponse
    {
        $record = PreboardingRecord::findOrFail($preboarding);
        $this->authorizeOrg($record, $request);

        $data = $request->validate([
            'medical_cover_eligible' => 'nullable|boolean',
            'link_expiry_days'       => 'nullable|integer|min:1|max:30',
            'enabled_sections'       => 'nullable|array',
            'enabled_sections.*'     => 'string',
        ]);

        $updates = array_filter([
            'medical_cover_eligible' => $data['medical_cover_eligible'] ?? null,
            'link_expiry_days'       => $data['link_expiry_days'] ?? null,
        ], fn($v) => $v !== null);

        if (isset($data['enabled_sections'])) {
            $updates['form_config'] = array_merge($record->form_config ?? [], ['enabled_sections' => $data['enabled_sections']]);
        }

        $record->update($updates);
        return response()->json($record->fresh());
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function authorizeOrg(PreboardingRecord $record, Request $request): void
    {
        abort_unless($record->organization_id === $this->orgId($request), 403, 'Forbidden.');
        $this->guardPermission($request);
    }

    /**
     * Tenancy was the only gate here — any signed-in member of the org could
     * read (and edit) pre-hire records: names, references, attachments.
     * Reads answer to onboarding.view, everything that mutates to
     * onboarding.manage, inferred from the HTTP verb so all twenty-odd
     * methods stand behind one rule.
     */
    private function guardPermission(Request $request): void
    {
        $permission = $request->isMethodSafe()
            ? \App\Support\Authorization\Permission::ONBOARDING_VIEW
            : \App\Support\Authorization\Permission::ONBOARDING_MANAGE;

        abort_unless(
            $request->user()->hasPermission(\App\Support\Authorization\Permission::ONBOARDING_MANAGE)
                || ($request->isMethodSafe() && $request->user()->hasPermission($permission)),
            403,
        );
    }
}
