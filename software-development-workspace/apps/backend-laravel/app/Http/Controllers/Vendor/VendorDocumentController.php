<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Training\VendorPartner;
use App\Models\Vendor\StakeholderRole;
use App\Models\Vendor\StakeholderRoleRequirement;
use App\Models\Vendor\VendorDocument;
use App\Services\Vendor\VendorReadiness;
use App\Support\Storage\DocumentVault;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A supplier's compliance paperwork, and what its roles demand of it.
 *
 * Files never leave the disk directly — they stream back through here, from
 * the encrypted vault, the same way employee documents do.
 */
class VendorDocumentController extends Controller
{
    private const MAX_SIZE_BYTES = 10 * 1024 * 1024;

    public function __construct(private readonly VendorReadiness $readiness) {}

    /**
     * Everything filed, plus the checklist its roles produce.
     *
     * One call rather than two because the question is always "what do we
     * have and what is still missing" — served apart, the screen would show a
     * document list that cannot say whether it is enough.
     */
    public function index(VendorPartner $vendor): JsonResponse
    {
        $this->authorize('view', $vendor);

        $vendor->load('stakeholderRoles');

        return response()->json([
            'data' => $vendor->documents()->get()->map(fn (VendorDocument $d) => $this->payload($d)),
            'meta' => [
                'checklist' => $this->readiness->checklist($vendor),
                'blockers'  => $this->readiness->blockers($vendor),
            ],
        ]);
    }

    public function store(Request $request, VendorPartner $vendor): JsonResponse
    {
        $this->authorize('update', $vendor);

        $data = $request->validate([
            'file'         => ['required', 'file', 'max:' . (self::MAX_SIZE_BYTES / 1024), 'mimes:pdf,jpg,jpeg,png,webp,doc,docx'],
            'document_key' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/'],
            'label'        => ['required', 'string', 'max:160'],
            'issue_date'   => ['nullable', 'date'],
            'expiry_date'  => ['nullable', 'date'],
            'is_perpetual' => ['sometimes', 'boolean'],
            'notes'        => ['nullable', 'string', 'max:500'],
        ]);

        $perpetual = (bool) ($data['is_perpetual'] ?? false);

        /*
         * A document that neither expires nor is declared perpetual would
         * sit in the register with nobody able to say whether it is still
         * good. One of the two answers is required.
         */
        if (! $perpetual && empty($data['expiry_date'])) {
            return response()->json([
                'message' => 'Give an expiry date, or mark the document as one that never expires.',
                'errors'  => ['expiry_date' => ['Required unless the document is perpetual.']],
            ], 422);
        }

        $file = $request->file('file');

        $document = $vendor->documents()->create([
            'organization_id' => $vendor->organization_id,
            'document_key'    => $data['document_key'],
            'label'           => $data['label'],
            'original_name'   => $file->getClientOriginalName(),
            'storage_path'    => DocumentVault::store($file, VendorDocument::DISK, (string) $vendor->id),
            'mime_type'       => $file->getMimeType(),
            'size_bytes'      => $file->getSize(),
            'issue_date'      => $data['issue_date'] ?? null,
            'expiry_date'     => $perpetual ? null : $data['expiry_date'],
            'is_perpetual'    => $perpetual,
            'notes'           => $data['notes'] ?? null,
            'uploaded_by'     => $request->user()->id,
        ]);

        return response()->json(['data' => $this->payload($document)], 201);
    }

    /** Correct the dates on a filed document without re-uploading it. */
    public function update(Request $request, VendorPartner $vendor, VendorDocument $document): JsonResponse
    {
        $this->authorize('update', $vendor);
        abort_if($document->vendor_partner_id !== $vendor->id, 404);

        $data = $request->validate([
            'label'        => ['sometimes', 'string', 'max:160'],
            'issue_date'   => ['nullable', 'date'],
            'expiry_date'  => ['nullable', 'date'],
            'is_perpetual' => ['sometimes', 'boolean'],
            'notes'        => ['nullable', 'string', 'max:500'],
        ]);

        if (($data['is_perpetual'] ?? $document->is_perpetual)) {
            $data['expiry_date'] = null;
        }

        $document->update($data);

        /*
         * A corrected date is a different deadline: the rungs already rung
         * against the old one would otherwise suppress every warning for the
         * new one.
         */
        if (array_key_exists('expiry_date', $data) || array_key_exists('is_perpetual', $data)) {
            $document->reminders()->delete();
        }

        return response()->json(['data' => $this->payload($document->fresh())]);
    }

    public function download(VendorPartner $vendor, VendorDocument $document): Response
    {
        $this->authorize('view', $vendor);
        abort_if($document->vendor_partner_id !== $vendor->id, 404);

        return DocumentVault::download(
            VendorDocument::DISK, $document->storage_path,
            $document->original_name, $document->mime_type,
        );
    }

    public function destroy(VendorPartner $vendor, VendorDocument $document): JsonResponse
    {
        $this->authorize('update', $vendor);
        abort_if($document->vendor_partner_id !== $vendor->id, 404);

        // Soft-deleted: what a supplier produced, and when, is part of the
        // audit answer even after somebody files a replacement.
        $document->delete();

        return response()->json(null, 204);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Requirements administration
    // ─────────────────────────────────────────────────────────────────────

    /** What each role demands, for the settings screen. */
    public function requirements(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission(\App\Support\Authorization\Permission::VENDORS_VIEW), 403);

        $orgId = app('current.organization_id');

        // The rulebook AS IT APPLIES: an organisation that has never edited
        // one sees the baseline it is actually being held to, with null ids
        // marking rows that exist only as defaults until somebody edits them.
        $effective = StakeholderRoleRequirement::forOrganization($orgId)
            ->sortBy([['role', 'asc'], ['label', 'asc']])
            ->map(fn (StakeholderRoleRequirement $r) => [
                'id'           => $r->id,
                'role'         => $r->role,
                'document_key' => $r->document_key,
                'label'        => $r->label,
                'requirement'  => $r->requirement,
                'help'         => $r->help,
                'is_active'    => $r->is_active,
                'is_default'   => $r->id === null,
            ])->values();

        return response()->json([
            'data' => $effective,
            'meta' => [
                'roles'      => StakeholderRole::ROLES,
                'customised' => StakeholderRoleRequirement::where('organization_id', $orgId)->exists(),
            ],
        ]);
    }

    /**
     * Set the rule for one role and one document.
     *
     * An upsert rather than create-plus-update: the identity of a rule is
     * (role, document key), and two endpoints for one idea would let a client
     * add "tax compliance for suppliers" twice and then wonder which one
     * counts.
     */
    public function storeRequirement(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission(\App\Support\Authorization\Permission::VENDORS_MANAGE), 403);

        $data = $request->validate([
            'role'         => ['required', 'string', 'in:' . implode(',', StakeholderRole::ROLES)],
            'document_key' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/'],
            'label'        => ['required', 'string', 'max:160'],
            'requirement'  => ['required', 'in:' . implode(',', StakeholderRoleRequirement::REQUIREMENTS)],
            'help'         => ['nullable', 'string', 'max:300'],
            'is_active'    => ['sometimes', 'boolean'],
        ]);

        $orgId = app('current.organization_id');

        /*
         * Editing one rule means owning the whole list. Until now this tenant
         * may have been running on the baseline; writing it out in full first
         * means the change reads as "this rule differs" rather than silently
         * discarding every default that was in force a moment ago.
         */
        StakeholderRoleRequirement::materialiseFor($orgId);

        $requirement = StakeholderRoleRequirement::updateOrCreate(
            [
                'organization_id' => $orgId,
                'role'            => $data['role'],
                'document_key'    => $data['document_key'],
            ],
            [
                'label'       => $data['label'],
                'requirement' => $data['requirement'],
                'help'        => $data['help'] ?? null,
                'is_active'   => $data['is_active'] ?? true,
            ],
        );

        return response()->json(['data' => $requirement], 201);
    }

    private function payload(VendorDocument $document): array
    {
        return [
            'id'            => $document->id,
            'document_key'  => $document->document_key,
            'label'         => $document->label,
            'original_name' => $document->original_name,
            'mime_type'     => $document->mime_type,
            'size_bytes'    => $document->size_bytes,
            'issue_date'    => $document->issue_date?->toDateString(),
            'expiry_date'   => $document->expiry_date?->toDateString(),
            'is_perpetual'  => $document->is_perpetual,
            'is_expired'    => $document->isExpired(),
            'days_left'     => $document->daysToExpiry(),
            'notes'         => $document->notes,
            'uploaded_at'   => $document->created_at?->toDateTimeString(),
        ];
    }
}
