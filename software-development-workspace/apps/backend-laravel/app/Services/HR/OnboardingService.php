<?php

namespace App\Services\HR;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\OnboardingAsset;
use App\Models\OnboardingDocumentChecklist;
use App\Models\OnboardingInduction;
use App\Support\Storage\DocumentVault;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class OnboardingService
{
    // ── Default checklist templates ───────────────────────────────────────────

    private const DEFAULT_INDUCTIONS = [
        'Organisation orientation',
        'HR policies & handbook briefing',
        'Health & safety induction',
        'IT systems & security training',
        'Department-specific induction',
        'Meet the team introduction',
    ];

    private const DEFAULT_DOCUMENTS = [
        'Offer letter signed',
        'Employment contract signed',
        'National ID / Passport copy',
        'KRA PIN certificate',
        'Academic certificates',
        'Passport photo',
        'NHIF card copy',
        'NSSF card copy',
        'Bank account details',
    ];

    // ── Inductions ────────────────────────────────────────────────────────────

    /**
     * Seed the default induction checklist for a newly onboarded employee.
     */
    public function seedInductions(int $employeeId): void
    {
        foreach (self::DEFAULT_INDUCTIONS as $i => $label) {
            OnboardingInduction::firstOrCreate(
                ['employee_id' => $employeeId, 'label' => $label],
                ['sort_order' => $i]
            );
        }
    }

    public function getInductions(int $employeeId): Collection
    {
        return OnboardingInduction::where('employee_id', $employeeId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Upsert the full induction list for an employee.
     * $items = [['label' => '...', 'done' => true, 'notes' => '...'], ...]
     */
    public function syncInductions(int $employeeId, array $items): Collection
    {
        foreach ($items as $i => $item) {
            OnboardingInduction::updateOrCreate(
                ['employee_id' => $employeeId, 'label' => $item['label']],
                ['done' => $item['done'] ?? false, 'notes' => $item['notes'] ?? null, 'sort_order' => $i]
            );
        }

        return $this->getInductions($employeeId);
    }

    // ── Document checklist ────────────────────────────────────────────────────

    public function seedDocumentChecklist(int $employeeId): void
    {
        foreach (self::DEFAULT_DOCUMENTS as $i => $label) {
            OnboardingDocumentChecklist::firstOrCreate(
                ['employee_id' => $employeeId, 'label' => $label],
                ['sort_order' => $i]
            );
        }
    }

    public function getDocumentChecklist(int $employeeId): Collection
    {
        return OnboardingDocumentChecklist::where('employee_id', $employeeId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function syncDocumentChecklist(int $employeeId, array $items): Collection
    {
        foreach ($items as $i => $item) {
            OnboardingDocumentChecklist::updateOrCreate(
                ['employee_id' => $employeeId, 'label' => $item['label']],
                ['done' => $item['done'] ?? false, 'notes' => $item['notes'] ?? null, 'sort_order' => $i]
            );
        }

        return $this->getDocumentChecklist($employeeId);
    }

    // ── Assets ────────────────────────────────────────────────────────────────

    public function getAssets(int $employeeId): Collection
    {
        return OnboardingAsset::where('employee_id', $employeeId)
            ->orderBy('id')
            ->get();
    }

    /**
     * Replace all asset assignments with the provided list.
     * $items = [['name' => '...', 'serial' => '...', 'assigned_date' => '...'], ...]
     */
    public function syncAssets(int $employeeId, array $items): Collection
    {
        // Soft-delete removed entries, upsert current ones
        $incoming = collect($items)->pluck('name')->filter()->values();

        OnboardingAsset::where('employee_id', $employeeId)
            ->whereNotIn('name', $incoming)
            ->delete();

        foreach ($items as $item) {
            if (empty(trim($item['name'] ?? ''))) {
                continue;
            }
            OnboardingAsset::updateOrCreate(
                ['employee_id' => $employeeId, 'name' => $item['name']],
                [
                    'serial_number' => $item['serial'] ?? null,
                    'assigned_date' => $item['assigned_date'] ?? null,
                ]
            );
        }

        return $this->getAssets($employeeId);
    }

    // ── Document file uploads ─────────────────────────────────────────────────

    /**
     * Store an uploaded file encrypted and persist the metadata record.
     *
     * Path pattern: {org_id}/{employee_id}/{category}/{uuid}.{ext}
     *
     * Written through the vault rather than straight to the disk. This class
     * holds employment contracts, ID scans and KRA PIN certificates, and it was
     * the one document class stored as plain files.
     */
    public function uploadDocument(
        int $employeeId,
        int $orgId,
        int $uploadedBy,
        string $category,
        UploadedFile $file
    ): EmployeeDocument {
        $ext      = $file->getClientOriginalExtension();
        $uuid     = (string) \Illuminate\Support\Str::uuid();
        $path     = "{$orgId}/{$employeeId}/{$category}/{$uuid}.{$ext}";

        DocumentVault::putContents(
            'employee_documents',
            $path,
            (string) file_get_contents($file->getRealPath()),
        );

        return EmployeeDocument::create([
            'employee_id'   => $employeeId,
            'uploaded_by'   => $uploadedBy,
            'category'      => $category,
            'original_name' => $file->getClientOriginalName(),
            'storage_path'  => $path,
            'mime_type'     => $file->getMimeType() ?? $file->getClientMimeType(),
            'size_bytes'    => $file->getSize(),
        ]);
    }

    public function getDocuments(int $employeeId): Collection
    {
        return EmployeeDocument::where('employee_id', $employeeId)
            ->orderBy('category')
            ->orderBy('created_at')
            ->get();
    }

    public function deleteDocument(EmployeeDocument $doc): void
    {
        Storage::disk('employee_documents')->delete($doc->storage_path);
        $doc->delete();
    }

    // ── Convenience: seed both checklists when a new employee is created ──────

    public function seedEmployee(int $employeeId): void
    {
        $this->seedInductions($employeeId);
        $this->seedDocumentChecklist($employeeId);
    }
}
