<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Services\HR\PreboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Public (unauthenticated) controller that allows pre-hires to:
 *  - View their assigned form via a secure token
 *  - Submit individual sections
 *  - Upload documents
 *
 * Access is controlled entirely by the single-use, time-limited token.
 * No HR session or Sanctum token is required.
 */
class PublicPreboardingController extends Controller
{
    public function __construct(private PreboardingService $service) {}

    // ── GET /public/preboarding/form/{token} ──────────────────────────────

    public function show(string $token): JsonResponse
    {
        try {
            $record = $this->service->getByToken($token);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['message' => 'Form not found. Please check your link.'], 404);
        } catch (\RuntimeException $e) {
            $code = $e->getCode();
            if ($code === 410) {
                return response()->json([
                    'message' => 'This form link has expired. Please contact HR to request a new link.',
                    'error'   => 'expired',
                ], 410);
            }
            if ($code === 403) {
                return response()->json([
                    'message' => 'This form has already been submitted. If you need to make changes, please contact HR.',
                    'error'   => 'already_used',
                ], 403);
            }
            return response()->json(['message' => 'Unable to access form.'], 400);
        }

        // Return a safe, limited view of the record — no internal IDs beyond what's needed
        return response()->json($this->formatPublicRecord($record));
    }

    // ── POST /public/preboarding/form/{token}/sections/{section} ─────────

    public function submitSection(Request $request, string $token, string $section): JsonResponse
    {
        $record = $this->resolveActiveRecord($token);
        if ($record instanceof JsonResponse) return $record;

        if (!in_array($section, PreboardingService::SECTIONS)) {
            return response()->json(['message' => 'Unknown section.'], 422);
        }

        // Verify the section is enabled for this record
        $enabledSections = $record->form_config['enabled_sections'] ?? PreboardingService::SECTIONS;
        if (!in_array($section, $enabledSections)) {
            return response()->json(['message' => 'This section is not part of your form.'], 403);
        }

        $data  = $request->validate(['data' => 'present|array']);
        $isDraft = (bool) $request->input('draft', false);

        $sec = $isDraft
            ? $this->service->saveSectionDraft($record, $section, $data['data'])
            : $this->service->updateSection($record, $section, $data['data']);

        return response()->json($sec);
    }

    // ── POST /public/preboarding/form/{token}/attachments ─────────────────

    public function uploadAttachment(Request $request, string $token): JsonResponse
    {
        $record = $this->resolveActiveRecord($token);
        if ($record instanceof JsonResponse) return $record;

        $request->validate([
            'file'     => 'required|file|mimes:pdf,jpg,jpeg,png,webp,doc,docx|max:10240',
            'category' => ['required', 'string', 'regex:/^[a-z0-9_]+$/'],
        ]);

        $att = $this->service->storeAttachment(
            $record,
            $request->file('file'),
            $request->input('category'),
            0 // no HR user — use 0 as "pre-hire uploaded"
        );

        return response()->json($att, 201);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Resolve a record from token without consuming the token again.
     * (token_used_at is already set from first GET access — we just validate it's
     * within the expiry window and the record is in_progress.)
     */
    private function resolveActiveRecord(string $token): mixed
    {
        $record = \App\Models\Preboarding\PreboardingRecord::with(['sections'])
            ->where('token', $token)
            ->first();

        if (!$record) {
            return response()->json(['message' => 'Form not found.'], 404);
        }

        if ($record->token_expires_at && $record->token_expires_at->isPast()) {
            return response()->json(['message' => 'Your form link has expired.', 'error' => 'expired'], 410);
        }

        if (!in_array($record->status, ['in_progress', 'pending'])) {
            return response()->json(['message' => 'Form submissions are closed for this record.'], 403);
        }

        return $record;
    }

    private function formatPublicRecord(\App\Models\Preboarding\PreboardingRecord $record): array
    {
        $enabledSections = $record->form_config['enabled_sections'] ?? PreboardingService::SECTIONS;

        // Load organisation for branding
        $org = \App\Models\Organization::find($record->organization_id);

        return [
            'first_name'             => $record->first_name,
            'last_name'              => $record->last_name,
            'position'               => $record->position,
            'employee_type'          => $record->employee_type,
            'expected_start_date'    => $record->expected_start_date?->toDateString(),
            'medical_cover_eligible' => $record->medical_cover_eligible,
            'enabled_sections'       => $enabledSections,
            'token_expires_at'       => $record->token_expires_at?->toIso8601String(),
            'status'                 => $record->status,
            'organization'           => $org ? [
                'name'          => $org->name,
                'logo_url'      => $org->logo_path
                    ? \Illuminate\Support\Facades\Storage::url($org->logo_path)
                    : null,
                'primary_color' => $org->primary_color,
                'country'       => $org->country,
            ] : null,
            'sections'               => $record->sections->map(fn($s) => [
                'section'      => $s->section,
                'status'       => $s->status,
                'submitted_at' => $s->submitted_at,
                'data'         => $s->data ?? [],
            ])->values(),
        ];
    }
}
