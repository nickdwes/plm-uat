<?php

namespace App\Services\HR;

use App\Models\Preboarding\PreboardingAttachment;
use App\Models\Preboarding\PreboardingRecord;
use App\Models\Preboarding\PreboardingReference;
use App\Models\Preboarding\PreboardingSection;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PreboardingService
{
    /** All section keys seeded on each new preboarding record */
    public const SECTIONS = [
        'self_intro',
        'personal',
        'contact',
        'emergency',
        'dependents',
        'immigration',
        'qualifications',
        'memberships',
        'attachments',
        'employment',   // HR-internal — never seeded automatically, created on first HR save
    ];

    // Sections that appear in the public candidate form (auto-seeded on record creation)
    private const CANDIDATE_SECTIONS = [
        'self_intro', 'personal', 'contact', 'emergency',
        'dependents', 'immigration', 'qualifications', 'memberships', 'attachments',
    ];

    // ── Preboarding Records ───────────────────────────────────────────────

    public function list(int $orgId, array $params = []): LengthAwarePaginator
    {
        $q = PreboardingRecord::where('organization_id', $orgId)
            ->with(['sections'])
            ->orderByDesc('created_at');

        if (!empty($params['search'])) {
            $s = '%' . $params['search'] . '%';
            $q->where(function ($q) use ($s) {
                $q->where('first_name', 'ilike', $s)
                  ->orWhere('last_name',  'ilike', $s)
                  ->orWhere('email',      'ilike', $s)
                  ->orWhere('position',   'ilike', $s);
            });
        }

        if (!empty($params['status'])) {
            $q->where('status', $params['status']);
        }

        return $q->paginate((int)($params['per_page'] ?? 20));
    }

    public function create(int $orgId, array $data, int $initiatedBy): PreboardingRecord
    {
        $expiryDays = max(1, (int)($data['link_expiry_days'] ?? 3));
        $enabledSections = $data['enabled_sections'] ?? null; // null = all
        $medicalEligible = (bool)($data['medical_cover_eligible'] ?? false);

        $formConfig = null;
        if ($enabledSections !== null) {
            $formConfig = ['enabled_sections' => $enabledSections];
        } elseif ($medicalEligible) {
            $formConfig = ['enabled_sections' => self::SECTIONS]; // all sections
        }

        $record = PreboardingRecord::create([
            'organization_id'        => $orgId,
            'candidate_id'           => $data['candidate_id'] ?? null,
            'first_name'             => $data['first_name'],
            'last_name'              => $data['last_name'],
            'email'                  => $data['email'],
            'phone'                  => $data['phone'] ?? null,
            'expected_start_date'    => $data['expected_start_date'] ?? null,
            'position'               => $data['position'] ?? null,
            'employee_type'          => $data['employee_type'] ?? null,
            'medical_cover_eligible' => $medicalEligible,
            'form_config'            => $formConfig,
            'link_expiry_days'       => $expiryDays,
            'notes'                  => $data['notes'] ?? null,
            'status'                 => 'pending',
            'token'                  => Str::random(56),
            'token_expires_at'       => now()->addDays($expiryDays),
            'initiated_by'           => $initiatedBy,
        ]);

        $this->seedSections($record->id, $enabledSections, $medicalEligible);

        return $record->load(['sections', 'references', 'attachments']);
    }

    /**
     * Regenerate the secure access token and reset the expiry window.
     */
    public function resendLink(PreboardingRecord $record): PreboardingRecord
    {
        $record->update([
            'token'             => Str::random(56),
            'token_expires_at'  => now()->addDays($record->link_expiry_days ?: 3),
            'token_used_at'     => null,
            'link_resend_count' => $record->link_resend_count + 1,
        ]);
        return $record->fresh();
    }

    /**
     * Look up a record by token, enforcing expiry and single-use.
     * Marks the token as used on first access.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     * @throws \RuntimeException with 'expired' or 'already_used' codes
     */
    public function getByToken(string $token): PreboardingRecord
    {
        $record = PreboardingRecord::with(['sections', 'references', 'attachments'])
            ->where('token', $token)
            ->firstOrFail();

        if ($record->token_expires_at && $record->token_expires_at->isPast()) {
            throw new \RuntimeException('expired', 410);
        }

        // If the record is already submitted/completed, block further access.
        // But allow the pre-hire to return to an in_progress form (e.g. after refresh).
        if (in_array($record->status, ['submitted', 'completed', 'converted'])) {
            throw new \RuntimeException('already_used', 403);
        }

        // Mark as in-use on very first access (token_used_at not yet set)
        if ($record->token_used_at === null) {
            $record->update([
                'token_used_at' => now(),
                'status'        => 'in_progress',
            ]);
        }

        return $record->fresh(['sections', 'references', 'attachments']);
    }

    public function get(int $id): PreboardingRecord
    {
        return PreboardingRecord::with(['sections', 'references', 'attachments'])
            ->findOrFail($id);
    }

    public function update(PreboardingRecord $record, array $data): PreboardingRecord
    {
        $record->update(array_filter([
            'first_name'          => $data['first_name']          ?? null,
            'last_name'           => $data['last_name']           ?? null,
            'email'               => $data['email']               ?? null,
            'phone'               => $data['phone']               ?? null,
            'expected_start_date' => $data['expected_start_date'] ?? null,
            'position'            => $data['position']            ?? null,
            'notes'               => $data['notes']               ?? null,
        ], fn($v) => $v !== null));

        return $record->fresh(['sections', 'references', 'attachments']);
    }

    public function delete(PreboardingRecord $record): void
    {
        $record->delete();
    }

    // ── Welcome / Announcement ───────────────────────────────────────────

    public function markWelcomeSent(PreboardingRecord $record): PreboardingRecord
    {
        $record->update([
            'welcome_sent_at' => now(),
            'status'          => 'in_progress',
        ]);
        return $record->fresh(['sections', 'references', 'attachments']);
    }

    public function markAnnouncementSent(PreboardingRecord $record): PreboardingRecord
    {
        $record->update(['announcement_sent_at' => now()]);
        $this->recalcStatus($record);
        return $record->fresh(['sections', 'references', 'attachments']);
    }

    // ── Sections ─────────────────────────────────────────────────────────

    private function seedSections(int $preboardingId, ?array $enabledSections, bool $medicalEligible): void
    {
        // Only seed candidate-facing sections (employment is HR-internal and never auto-seeded)
        if ($enabledSections !== null) {
            $toSeed = array_intersect(self::CANDIDATE_SECTIONS, $enabledSections);
        } else {
            $toSeed = self::CANDIDATE_SECTIONS;
        }

        // Remove dependents if medical cover not eligible and not explicitly included
        if (!$medicalEligible && $enabledSections === null) {
            $toSeed = array_filter($toSeed, fn($s) => $s !== 'dependents');
        }

        foreach ($toSeed as $key) {
            PreboardingSection::firstOrCreate(
                ['preboarding_id' => $preboardingId, 'section' => $key],
                ['status' => 'pending']
            );
        }
    }

    public function updateSection(PreboardingRecord $record, string $section, array $data, string $role = 'hr'): PreboardingSection
    {
        // Use firstOrCreate so HR-only sections (e.g. employment) can be saved
        // even if they were never seeded as a candidate-facing section row.
        $sec = PreboardingSection::firstOrCreate(
            ['preboarding_id' => $record->id, 'section' => $section],
            ['status' => 'pending']
        );

        $sec->update([
            'data'         => $data,
            'status'       => 'submitted',
            'submitted_at' => now(),
        ]);

        if ($record->status === 'pending') {
            $record->update(['status' => 'in_progress']);
        }

        return $sec->fresh();
    }

    /** Save section data without marking it as submitted (draft/auto-save). */
    public function saveSectionDraft(PreboardingRecord $record, string $section, array $data): PreboardingSection
    {
        $sec = PreboardingSection::where('preboarding_id', $record->id)
            ->where('section', $section)
            ->firstOrFail();

        // Only update data; preserve existing status (pending stays pending)
        $sec->update(['data' => $data]);

        return $sec->fresh();
    }

    public function approveSection(PreboardingRecord $record, string $section, int $reviewerId, ?string $hrNotes = null): PreboardingSection
    {
        $sec = PreboardingSection::where('preboarding_id', $record->id)
            ->where('section', $section)
            ->firstOrFail();

        $sec->update([
            'status'      => 'approved',
            'reviewed_by' => $reviewerId,
            'reviewed_at' => now(),
            'hr_notes'    => $hrNotes,
        ]);

        $this->recalcStatus($record);

        return $sec->fresh();
    }

    public function markSectionNotApplicable(PreboardingRecord $record, string $section): PreboardingSection
    {
        $sec = PreboardingSection::where('preboarding_id', $record->id)
            ->where('section', $section)
            ->firstOrFail();

        $sec->update(['status' => 'not_applicable']);
        $this->recalcStatus($record);

        return $sec->fresh();
    }

    private function recalcStatus(PreboardingRecord $record): void
    {
        $record->refresh();
        $sections = $record->sections;
        $active   = $sections->where('status', '!=', 'not_applicable');

        if ($active->isEmpty()) return;

        $allApproved = $active->every(fn($s) => $s->status === 'approved');
        if ($allApproved && $record->announcement_sent_at) {
            $record->update(['status' => 'completed']);
        }
    }

    // ── References ───────────────────────────────────────────────────────

    public function addReference(PreboardingRecord $record, array $data): PreboardingReference
    {
        return PreboardingReference::create([
            'preboarding_id'    => $record->id,
            'referee_name'      => $data['referee_name'],
            'referee_email'     => $data['referee_email'],
            'referee_company'   => $data['referee_company']   ?? null,
            'referee_job_title' => $data['referee_job_title'] ?? null,
            'relationship'      => $data['relationship']      ?? null,
            'status'            => 'pending',
        ]);
    }

    public function approveReference(PreboardingReference $ref): PreboardingReference
    {
        $ref->update(['status' => 'approved']);
        return $ref->fresh();
    }

    public function inviteReference(PreboardingReference $ref): PreboardingReference
    {
        $ref->update([
            'status'     => 'invited',
            'invited_at' => now(),
        ]);
        return $ref->fresh();
    }

    public function deleteReference(PreboardingReference $ref): void
    {
        $ref->delete();
    }

    // ── Attachments ──────────────────────────────────────────────────────

    private const ALLOWED_MIMES = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx'];
    private const MAX_BYTES = 10 * 1024 * 1024; // 10 MB

    public function storeAttachment(PreboardingRecord $record, UploadedFile $file, string $category, int $uploadedBy): PreboardingAttachment
    {
        $ext = strtolower($file->getClientOriginalExtension());

        // Auto-rename for consistency: FirstName_LastName_CATEGORY_YYYY-MM-DD.ext
        $safeFirst    = Str::slug($record->first_name, '_');
        $safeLast     = Str::slug($record->last_name, '_');
        $safeCategory = strtoupper($category);
        $dateStr      = now()->format('Y-m-d');
        $standardName = "{$safeFirst}_{$safeLast}_{$safeCategory}_{$dateStr}.{$ext}";

        $path = "{$record->organization_id}/{$record->id}/{$category}/{$standardName}";

        Storage::disk('preboarding_docs')->putFileAs(
            dirname($path),
            $file,
            basename($path)
        );

        return PreboardingAttachment::create([
            'preboarding_id' => $record->id,
            'category'       => $category,
            'original_name'  => $standardName, // standardised name
            'storage_path'   => $path,
            'mime_type'      => $file->getMimeType(),
            'size_bytes'     => $file->getSize(),
            'uploaded_by'    => $uploadedBy,
        ]);
    }

    public function deleteAttachment(PreboardingAttachment $att): void
    {
        if ($att->fileExists()) {
            Storage::disk('preboarding_docs')->delete($att->storage_path);
        }
        $att->delete();
    }

    // ── Convert to Employee ──────────────────────────────────────────────

    /** Mark a preboarding record as converted and link the new employee id. */
    public function markConverted(PreboardingRecord $record, int $employeeId): PreboardingRecord
    {
        $record->update([
            'employee_id' => $employeeId,
            'status'      => 'converted',
        ]);
        return $record->fresh();
    }
}
