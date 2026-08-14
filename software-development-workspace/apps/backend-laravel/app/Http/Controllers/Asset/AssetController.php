<?php

namespace App\Http\Controllers\Asset;

use App\Http\Controllers\Controller;
use App\Http\Requests\Asset\AssignAssetRequest;
use App\Http\Requests\Asset\StoreAssetRequest;
use App\Http\Requests\Asset\UpdateAssetRequest;
use App\Http\Resources\Asset\AssetResource;
use App\Models\Asset\Asset;
use App\Models\Asset\AssetRequest;
use Illuminate\Validation\Rule;
use App\Models\Asset\AssetRelationship;
use App\Models\Asset\AssetDocument;
use App\Models\Employee;
use App\Services\Asset\AssetRequestChain;
use App\Services\Asset\AssetService;
use App\Support\Storage\DocumentVault;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AssetController extends Controller
{
    public function __construct(
        private readonly AssetService $service,
        private readonly \App\Services\Asset\AssetLifecycle $lifecycle,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Asset::class);

        $filters = $request->only([
            'status', 'category', 'department_id', 'assigned', 'assigned_to',
            'search', 'warranty_expired', 'maintenance_due', 'asset_class',
            'has_spare_seats',
        ]);

        /*
         * "Mine" is the caller's own employee id, resolved here.
         *
         * The client cannot send it — it knows the signed-in user, not which
         * employee record that maps to — so asking it to would mean an extra
         * round trip and a second place that gets the mapping wrong.
         */
        if ($request->boolean('mine')) {
            $filters['assigned_to'] = $request->user()->employeeId() ?? -1;
        }

        $assets = $this->service->list(
            filters: $filters,
            perPage: (int) $request->input('per_page', 20),
        );

        return AssetResource::collection($assets);
    }

    public function show(Asset $asset): AssetResource
    {
        $this->authorize('view', $asset);

        // Events come with the asset because the detail page draws the
        // lifecycle timeline on arrival; a second round trip for something
        // always shown is a round trip nobody needed.
        /*
         * The category's field definitions travel beside the asset, so the
         * detail page can label and order the answers without a second call —
         * and so a value whose field has since been removed is simply not
         * shown rather than rendered under a raw key.
         */
        return (new AssetResource($asset->load([
            // `custodian` alongside `holder`: without it the detail page saves a
            // custodian and then renders "nobody answerable", because the
            // resource only emits relations that were actually loaded.
            'holder', 'custodian', 'department', 'events.employee', 'events.vendor',
            'seats.employee', 'photos', 'documents',
        ])))->additional([
            'custom_field_defs' => $asset->categoryFields()->get()->map(fn ($f) => [
                'key' => $f->key, 'label' => $f->label, 'type' => $f->type,
                'options' => $f->options, 'help' => $f->help,
                'is_required' => $f->is_required, 'section' => $f->section,
            ])->values(),
            'relationships' => $this->relationshipPayload($asset),
        ]);
    }

    /**
     * "I have it, and here is the state it arrived in."
     *
     * Authorised against the person, not a permission: accepting equipment is
     * something only the recipient can do, and an administrator signing on
     * somebody's behalf would defeat the point of a signature.
     */
    public function accept(Request $request, Asset $asset): AssetResource
    {
        $employeeId = $request->user()->employeeId();
        abort_if($employeeId === null, 422, 'You have no employee record.');

        $data = $request->validate([
            'note'    => ['nullable', 'string', 'max:500'],
            // The tick on the designs' form. Refused rather than assumed:
            // an acknowledgement nobody made is not an acknowledgement.
            'agreed'  => ['accepted'],
        ]);

        $accepted = app(AssetRequestChain::class)->accept($asset, $employeeId, $data['note'] ?? null);

        return new AssetResource($accepted->load(['holder', 'department']));
    }

    // ── Documents ─────────────────────────────────────────────────────────

    public function storeDocument(Request $request, Asset $asset): JsonResponse
    {
        $this->authorize('update', $asset);

        $data = $request->validate([
            'file'     => ['required', 'file', 'mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg', 'max:20480'],
            'category' => ['required', Rule::in(AssetDocument::CATEGORIES)],
            'note'     => ['nullable', 'string', 'max:255'],
        ]);

        $file = $request->file('file');

        // Encrypted, like every other document class. A signed vendor contract
        // carries commercial terms and signatures.
        $path = DocumentVault::store(
            $file, 'asset_documents', "{$asset->organization_id}/{$asset->id}/documents",
        );

        $asset->documents()->create([
            'organization_id' => $asset->organization_id,
            'category'      => $data['category'],
            'storage_path'  => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type'     => $file->getMimeType(),
            'size_bytes'    => $file->getSize(),
            'note'          => $data['note'] ?? null,
            'uploaded_by'   => $request->user()->id,
        ]);

        return response()->json(['data' => $this->documentPayload($asset->fresh('documents'))]);
    }

    public function downloadDocument(Asset $asset, int $document)
    {
        $this->authorize('view', $asset);

        $record = $asset->documents()->findOrFail($document);

        return DocumentVault::download(
            'asset_documents', $record->storage_path, $record->original_name, $record->mime_type,
        );
    }

    public function destroyDocument(Asset $asset, int $document): JsonResponse
    {
        $this->authorize('update', $asset);

        $record = $asset->documents()->findOrFail($document);
        DocumentVault::delete('asset_documents', $record->storage_path);
        $record->delete();

        return response()->json(['data' => $this->documentPayload($asset->fresh('documents'))]);
    }

    private function documentPayload(Asset $asset): array
    {
        return $asset->documents->map(fn ($d) => [
            'id' => $d->id, 'category' => $d->category, 'note' => $d->note,
            'original_name' => $d->original_name, 'size_bytes' => $d->size_bytes,
            'uploaded_at' => $d->created_at?->toDateString(),
        ])->values()->all();
    }

    // ── Relationships ─────────────────────────────────────────────────────

    /**
     * Record that one asset depends on another.
     *
     * Directional. "The CRM runs on SRV-01" and the reverse are different
     * claims, and only one of them tells you what breaks when the server is
     * rebooted.
     */
    public function storeRelationship(Request $request, Asset $asset): JsonResponse
    {
        $this->authorize('update', $asset);

        $data = $request->validate([
            'related_asset_id' => ['required', 'integer', 'exists:assets,id', 'different:__self'],
            'relationship'     => ['required', Rule::in(AssetRelationship::TYPES)],
            'note'             => ['nullable', 'string', 'max:255'],
        ]);

        // Nothing depends on itself, and a register that allows it will grow a
        // cycle somebody has to debug.
        abort_if((int) $data['related_asset_id'] === $asset->id, 422, 'An asset cannot depend on itself.');

        $asset->dependencies()->firstOrCreate(
            ['related_asset_id' => $data['related_asset_id'], 'relationship' => $data['relationship']],
            ['organization_id' => $asset->organization_id, 'note' => $data['note'] ?? null,
             'created_by' => $request->user()->id],
        );

        return response()->json(['data' => $this->relationshipPayload($asset->fresh())]);
    }

    public function destroyRelationship(Asset $asset, int $relationship): JsonResponse
    {
        $this->authorize('update', $asset);

        $asset->dependencies()->findOrFail($relationship)->delete();

        return response()->json(['data' => $this->relationshipPayload($asset->fresh())]);
    }

    /**
     * Both directions, from one asset's point of view.
     *
     * What it depends on, and what depends on it. The second is the list read
     * before a reboot, and it is stored on the other rows rather than
     * duplicated here — two rows for one fact is two rows to keep in step.
     */
    private function relationshipPayload(Asset $asset): array
    {
        $out = ['depends_on' => [], 'depended_on_by' => []];

        foreach ($asset->dependencies()->with('related:id,name,asset_tag,asset_class')->get() as $r) {
            $out['depends_on'][] = [
                'id' => $r->id, 'relationship' => $r->relationship, 'note' => $r->note,
                'asset' => $r->related?->only(['id', 'name', 'asset_tag', 'asset_class']),
            ];
        }

        foreach ($asset->dependents()->with('asset:id,name,asset_tag,asset_class')->get() as $r) {
            $out['depended_on_by'][] = [
                'id' => $r->id,
                'relationship' => AssetRelationship::INVERSE_LABEL[$r->relationship] ?? $r->relationship,
                'asset' => $r->asset?->only(['id', 'name', 'asset_tag', 'asset_class']),
            ];
        }

        return $out;
    }

    // ── Photographs ───────────────────────────────────────────────────────

    /**
     * Add a photograph of the asset.
     *
     * Through the vault on the asset documents disk. A photograph of a server
     * rack shows the serial numbers of everything in it, and a picture of a
     * laptop shows an asset tag — neither belongs on a URL that needs no
     * authentication.
     */
    public function storePhoto(Request $request, Asset $asset): JsonResponse
    {
        $this->authorize('update', $asset);

        $data = $request->validate([
            'photo'       => ['required', 'file', 'mimes:jpg,jpeg,png,webp,heic', 'max:10240'],
            'caption'     => ['nullable', 'string', 'max:255'],
            // Taken at the moment custody changed, as opposed to today. Only
            // the first kind settles an argument about how it was handed over.
            'at_handover' => ['nullable', 'boolean'],
            'is_primary'  => ['nullable', 'boolean'],
        ]);

        $file = $request->file('photo');

        $path = DocumentVault::store(
            $file,
            'asset_documents',
            "{$asset->organization_id}/{$asset->id}/photos",
        );

        $primary = $request->boolean('is_primary')
            // The first photograph becomes the one shown elsewhere, because a
            // register with pictures and no thumbnail helps nobody.
            || ! $asset->photos()->where('is_primary', true)->exists();

        if ($primary) {
            $asset->photos()->update(['is_primary' => false]);
        }

        $photo = $asset->photos()->create([
            'organization_id' => $asset->organization_id,
            'storage_path'    => $path,
            'original_name'   => $file->getClientOriginalName(),
            'mime_type'       => $file->getMimeType(),
            'size_bytes'      => $file->getSize(),
            'caption'         => $data['caption'] ?? null,
            'at_handover'     => $request->boolean('at_handover'),
            'is_primary'      => $primary,
            'uploaded_by'     => $request->user()->id,
        ]);

        return response()->json(['data' => $this->photoPayload($asset->fresh('photos'))]);
    }

    /** The image itself, decrypted, for somebody allowed to see the asset. */
    public function showPhoto(Asset $asset, int $photo)
    {
        $this->authorize('view', $asset);

        $record = $asset->photos()->findOrFail($photo);

        return DocumentVault::download(
            'asset_documents',
            $record->storage_path,
            $record->original_name,
            $record->mime_type,
        );
    }

    public function destroyPhoto(Asset $asset, int $photo): JsonResponse
    {
        $this->authorize('update', $asset);

        $record = $asset->photos()->findOrFail($photo);
        DocumentVault::delete('asset_documents', $record->storage_path);
        $wasPrimary = $record->is_primary;
        $record->delete();

        // Something has to be the one shown, so the next one becomes it.
        if ($wasPrimary) {
            $asset->photos()->first()?->update(['is_primary' => true]);
        }

        return response()->json(['data' => $this->photoPayload($asset->fresh('photos'))]);
    }

    private function photoPayload(Asset $asset): array
    {
        return $asset->photos->map(fn ($p) => [
            'id'          => $p->id,
            'caption'     => $p->caption,
            'is_primary'  => $p->is_primary,
            'at_handover' => $p->at_handover,
            'original_name' => $p->original_name,
            'uploaded_at' => $p->created_at?->toDateString(),
        ])->values()->all();
    }

    /**
     * Hand a seat out of a licence batch.
     *
     * Its own endpoint rather than a field on update, for the same reason
     * assigning a laptop is: consuming a seat is a different act from
     * correcting a renewal date, and the register should not let one look like
     * the other.
     */
    public function allocateSeat(Request $request, Asset $asset): AssetResource
    {
        $this->authorize('assign', $asset);

        $data = $request->validate([
            'employee_id'  => ['required', 'integer', 'exists:employees,id'],
            'allocated_on' => ['nullable', 'date'],
            'note'         => ['nullable', 'string', 'max:255'],
        ]);

        $asset->allocateSeat(
            (int) $data['employee_id'],
            $data['allocated_on'] ?? null,
            $data['note'] ?? null,
            $request->user()->id,
        );

        return new AssetResource($asset->fresh(['holder', 'department', 'seats.employee']));
    }

    /**
     * Give a seat back.
     *
     * Released, not deleted — a seat held for eight months and returned is part
     * of what a renewal conversation needs, and removing it would make a
     * fully-consumed licence read as under-used for the year it was full.
     */
    public function releaseSeat(Request $request, Asset $asset, int $allocation): AssetResource
    {
        $this->authorize('assign', $asset);

        $seat = $asset->seats()->findOrFail($allocation);
        $seat->update(['released_on' => $request->input('released_on', now()->toDateString())]);

        return new AssetResource($asset->fresh(['holder', 'department', 'seats.employee']));
    }

    /**
     * Who hears about a renewal, and whether anybody does.
     *
     * Both in one call because they are one decision — turning reminders on
     * with nobody to send them to is the setting that looks configured and does
     * nothing.
     */
    public function renewalWatchers(Request $request, Asset $asset): JsonResponse
    {
        $this->authorize('update', $asset);

        $data = $request->validate([
            'reminders_on'  => ['required', 'boolean'],
            'employee_ids'  => ['nullable', 'array'],
            'employee_ids.*' => ['integer', 'exists:employees,id'],
        ]);

        $asset->update(['renewal_reminders_on' => $data['reminders_on']]);

        if (array_key_exists('employee_ids', $data)) {
            $asset->watchers()->delete();

            foreach (array_unique($data['employee_ids'] ?? []) as $id) {
                $asset->watchers()->create(['employee_id' => $id]);
            }
        }

        return response()->json([
            'data' => $asset->fresh(['watchers.employee'])->watchers->map(fn ($w) => [
                'id' => $w->id, 'employee_id' => $w->employee_id, 'name' => $w->employee?->full_name,
            ])->values(),
            'reminders_on' => $asset->fresh()->renewal_reminders_on,
        ]);
    }

    /**
     * The numbers across the top of the Assets page.
     *
     * Counted from the same rows the tabs list, so a card and the list beneath
     * it can never tell different stories — the failure mode of every dashboard
     * assembled from its own queries.
     */
    public function summary(): JsonResponse
    {
        $this->authorize('viewAny', Asset::class);

        $base = fn () => Asset::query()->where('status', '!=', Asset::STATUS_RETIRED);

        return response()->json([
            'totals' => [
                'assigned'  => (clone $base())->whereNotNull('assigned_to')->count(),
                'available' => (clone $base())->where('status', Asset::STATUS_AVAILABLE)->count(),
                /*
                 * Running and looked after, rather than held or on a shelf.
                 *
                 * Without this the tiles silently omit every cloud resource and
                 * every racked switch: the first Azure import put thirteen
                 * assets in the register and the summary still read "1
                 * available", which is worse than a wrong number because it
                 * looks like nothing happened.
                 */
                'in_service' => (clone $base())->where('status', Asset::STATUS_IN_SERVICE)->count(),
                'looked_after' => (clone $base())->whereNotNull('custodian_id')->count(),
                // Stopped by the vendor. Its own count, because it is chased by
                // whoever handles the account rather than by whoever fixes kit.
                'suspended' => (clone $base())->where('status', Asset::STATUS_SUSPENDED)->count(),
                'pending_requests' => AssetRequest::where('status', AssetRequest::STATUS_PENDING)->count(),
                'under_repair' => (clone $base())->where('status', Asset::STATUS_MAINTENANCE)->count(),
            ],

            'details' => [
                // The subscription work made this one answerable at all.
                'licences_expiring' => Asset::expiringSoon()->count(),
                'due_for_return'    => (clone $base())->whereNotNull('assigned_to')
                    ->whereNotNull('expires_on')->count(),
                'maintenance_due'   => (clone $base())->whereNotNull('next_maintenance')
                    ->whereDate('next_maintenance', '<=', now()->toDateString())->count(),
                /*
                 * What the register is worth, retired items excluded.
                 *
                 * Purchase value, not book value — there is no depreciation in
                 * this system, and presenting a purchase total as if it were
                 * current worth is the kind of number finance quotes back at
                 * you a year later.
                 */
                'purchase_value'    => (float) (clone $base())->sum('purchase_value'),
            ],

            // The three charts. Counted here rather than in the client so the
            // donut and the table behind it cannot disagree.
            'by_category'   => (clone $base())->selectRaw('category as label, count(*) as count')
                ->groupBy('category')->orderByDesc('count')->get(),
            'by_department' => (clone $base())->join('departments', 'departments.id', '=', 'assets.department_id')
                ->selectRaw('departments.name as label, count(*) as count')
                ->groupBy('departments.name')->orderByDesc('count')->get(),
            'by_status'     => Asset::selectRaw('status as label, count(*) as count')
                ->groupBy('status')->get(),
        ]);
    }

    public function store(StoreAssetRequest $request): AssetResource
    {
        $asset = $this->service->create(
            organizationId: app('current.organization_id'),
            data: $request->validated(),
        );

        return new AssetResource($asset->load(['holder', 'department']));
    }

    public function update(UpdateAssetRequest $request, Asset $asset): AssetResource
    {
        return new AssetResource($this->service->update($asset, $request->validated()));
    }

    public function destroy(Asset $asset): JsonResponse
    {
        $this->authorize('delete', $asset);

        // Soft delete — an asset that has been in someone's hands is part of
        // the custody record, and a hard delete would take that with it.
        $asset->delete();

        return response()->json(['message' => 'Asset retired from the register.']);
    }

    /** Hand the asset to an employee. */
    public function assign(AssignAssetRequest $request, Asset $asset): AssetResource
    {
        $employee = Employee::findOrFail($request->integer('employee_id'));

        // The employee must be in the same tenant. Without this, a valid
        // employee id from another organization would pass validation — the
        // exists rule does not know about tenancy.
        abort_unless(
            (int) $employee->primary_organization_id === (int) app('current.organization_id'),
            422,
            'That employee is not in the current organization.'
        );

        return new AssetResource(
            $this->service->assign(
                $asset,
                $employee,
                $request->input('assigned_date'),
                // Whatever the form ticked; the return screen reads it back.
                $request->has('accessories') ? (array) $request->input('accessories', []) : null,
                $request->input('expected_return_date'),
            )
        );
    }

    /**
     * Record who is answerable for something nobody carries.
     *
     * A separate endpoint from `assign` because it is a separate act with
     * different consequences — no handover, no acceptance, and nothing to
     * return at an exit interview.
     */
    public function setCustodian(Request $request, Asset $asset): AssetResource
    {
        $this->authorize('assign', $asset);

        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'note'        => ['nullable', 'string', 'max:500'],
        ]);

        $employee = Employee::findOrFail($data['employee_id']);

        // Same tenancy check as assignment: `exists` knows nothing about which
        // organisation an employee belongs to.
        abort_unless(
            (int) $employee->primary_organization_id === (int) app('current.organization_id'),
            422,
            'That employee is not in the current organization.',
        );

        $this->lifecycle->setCustodian(
            $asset,
            $employee->id,
            array_filter(['note' => $data['note'] ?? null]),
            $request->user()?->employeeId(),
        );

        return new AssetResource($asset->fresh(['custodian', 'holder', 'assetCategory']));
    }

    /** Nobody is answerable for it at the moment. */
    public function clearCustodian(Request $request, Asset $asset): AssetResource
    {
        $this->authorize('assign', $asset);

        $this->lifecycle->clearCustodian(
            $asset,
            array_filter(['note' => $request->input('note')]),
            $request->user()?->employeeId(),
        );

        return new AssetResource($asset->fresh(['custodian', 'holder', 'assetCategory']));
    }

    /** Take the asset back. */
    public function release(Request $request, Asset $asset): AssetResource
    {
        $this->authorize('assign', $asset);

        $status = $request->input('status', Asset::STATUS_AVAILABLE);

        abort_unless(
            in_array($status, [Asset::STATUS_AVAILABLE, Asset::STATUS_MAINTENANCE, Asset::STATUS_RETIRED], true),
            422,
            'An asset can only be released to available, maintenance or retired.'
        );

        return new AssetResource($this->service->release($asset, $status));
    }
}
