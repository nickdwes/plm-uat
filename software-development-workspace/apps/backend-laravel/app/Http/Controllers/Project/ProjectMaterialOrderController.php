<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project\Project;
use App\Models\Project\ProjectAttachment;
use App\Models\Project\ProjectMaterialOrder;
use App\Models\Project\ProjectMaterialOrderContact;
use App\Models\Project\ProjectMaterialOrderTracker;
use App\Models\Project\ProjectMaterialOrderUpdate;
use App\Models\Training\VendorPartner;
use App\Support\Account\ReferenceSequence;
use App\Support\Storage\DocumentVault;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Supplier material ordering & delivery — orders and everything hung off one:
 * the tracker of checkpoints, delivery updates, logistics contacts and the LPO
 * and delivery-note documents.
 */
class ProjectMaterialOrderController extends Controller
{
    private const WITH = ['supplier:id,name', 'trackers.creator:id,name', 'updates.creator:id,name', 'contacts', 'documents'];

    public function index(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json([
            'data'     => $project->materialOrders()->with(self::WITH)->get()->map(fn ($o) => $this->payload($o)),
            'partners' => VendorPartner::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name]),
        ]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $data = $this->orderRules($request, $project, creating: true);

        $order = $project->materialOrders()->create($data + [
            'organization_id' => $project->organization_id,
            'reference'       => ReferenceSequence::next('MO', 'project_material_orders', $project->organization_id),
            'created_by'      => $request->user()->id,
        ]);

        return response()->json(['data' => $this->payload($order->load(self::WITH))], 201);
    }

    public function update(Request $request, Project $project, ProjectMaterialOrder $order): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($order->project_id === $project->id, 404);

        $order->update($this->orderRules($request, $project, creating: false));

        return response()->json(['data' => $this->payload($order->fresh(self::WITH))]);
    }

    public function destroy(Project $project, ProjectMaterialOrder $order): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($order->project_id === $project->id, 404);

        $order->delete();

        return response()->json(['message' => 'Order removed.']);
    }

    // ── Tracker ─────────────────────────────────────────────────────────────

    public function storeTracker(Request $request, Project $project, ProjectMaterialOrder $order): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($order->project_id === $project->id, 404);

        $data = $request->validate([
            'name'        => ['required', 'string', 'max:200'],
            'details'     => ['nullable', 'string', 'max:5000'],
            'happened_on' => ['nullable', 'date'],
        ]);

        $order->trackers()->create($data + ['organization_id' => $project->organization_id, 'created_by' => $request->user()->id]);

        return response()->json(['data' => $this->payload($order->fresh(self::WITH))], 201);
    }

    public function destroyTracker(Project $project, ProjectMaterialOrder $order, ProjectMaterialOrderTracker $tracker): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($order->project_id === $project->id && $tracker->material_order_id === $order->id, 404);

        $tracker->delete();

        return response()->json(['data' => $this->payload($order->fresh(self::WITH))]);
    }

    // ── Delivery updates ────────────────────────────────────────────────────

    public function storeUpdate(Request $request, Project $project, ProjectMaterialOrder $order): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($order->project_id === $project->id, 404);

        $data = $request->validate([
            'latest_supplier_update' => ['nullable', 'string', 'max:5000'],
            'delivery_plan_status'   => ['nullable', 'string', 'max:5000'],
        ]);

        if (empty($data['latest_supplier_update']) && empty($data['delivery_plan_status'])) {
            return response()->json(['message' => 'Add a supplier update or a delivery-plan status.'], 422);
        }

        $order->updates()->create($data + ['organization_id' => $project->organization_id, 'created_by' => $request->user()->id]);

        return response()->json(['data' => $this->payload($order->fresh(self::WITH))], 201);
    }

    public function destroyUpdate(Project $project, ProjectMaterialOrder $order, ProjectMaterialOrderUpdate $update): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($order->project_id === $project->id && $update->material_order_id === $order->id, 404);

        $update->delete();

        return response()->json(['data' => $this->payload($order->fresh(self::WITH))]);
    }

    // ── Logistics contacts ──────────────────────────────────────────────────

    public function storeContact(Request $request, Project $project, ProjectMaterialOrder $order): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($order->project_id === $project->id, 404);

        $data = $request->validate([
            'name'    => ['required', 'string', 'max:200'],
            'email'   => ['nullable', 'email', 'max:200'],
            'phone'   => ['nullable', 'string', 'max:60'],
            'address' => ['nullable', 'string', 'max:300'],
        ]);

        $order->contacts()->create($data + ['organization_id' => $project->organization_id, 'created_by' => $request->user()->id]);

        return response()->json(['data' => $this->payload($order->fresh(self::WITH))], 201);
    }

    public function destroyContact(Project $project, ProjectMaterialOrder $order, ProjectMaterialOrderContact $contact): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($order->project_id === $project->id && $contact->material_order_id === $order->id, 404);

        $contact->delete();

        return response()->json(['data' => $this->payload($order->fresh(self::WITH))]);
    }

    // ── Documents (LPO, delivery notes) ─────────────────────────────────────

    public function storeDocument(Request $request, Project $project, ProjectMaterialOrder $order): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($order->project_id === $project->id, 404);

        $data = $request->validate([
            'file'  => ['required', 'file', 'max:20480', 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,png,jpg,jpeg,zip'],
            'label' => ['nullable', 'string', 'max:255'],
        ]);

        $file = $request->file('file');

        $project->attachments()->create([
            'organization_id'   => $project->organization_id,
            'material_order_id' => $order->id,
            'document_type'     => 'order',
            'label'             => ($data['label'] ?? null) ?: $file->getClientOriginalName(),
            'original_name'     => $file->getClientOriginalName(),
            'storage_path'      => DocumentVault::store($file, 'project_documents', "projects/{$project->id}/orders/{$order->id}"),
            'mime_type'         => $file->getClientMimeType(),
            'size_bytes'        => $file->getSize(),
            'uploaded_by'       => $request->user()->id,
        ]);

        return response()->json(['data' => $this->payload($order->fresh(self::WITH))], 201);
    }

    public function downloadDocument(Project $project, ProjectMaterialOrder $order, ProjectAttachment $attachment): StreamedResponse
    {
        $this->authorize('view', $project);
        abort_unless($order->project_id === $project->id && $attachment->material_order_id === $order->id, 404);

        return DocumentVault::download(
            'project_documents', $attachment->storage_path,
            $attachment->original_name, $attachment->mime_type,
        );
    }

    public function destroyDocument(Project $project, ProjectMaterialOrder $order, ProjectAttachment $attachment): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($order->project_id === $project->id && $attachment->material_order_id === $order->id, 404);

        $attachment->delete();

        return response()->json(['data' => $this->payload($order->fresh(self::WITH))]);
    }

    // ── Internals ───────────────────────────────────────────────────────────

    private function orderRules(Request $request, Project $project, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        $data = $request->validate([
            'name'        => [$req, 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status'      => ['sometimes', Rule::in(ProjectMaterialOrder::STATUSES)],
            'due_date'    => ['nullable', 'date'],
            'vendor_partner_id' => ['nullable', 'integer', Rule::exists('vendor_partners', 'id')],
            'supplier_name'     => ['nullable', 'string', 'max:200'],
            'lpo_reference'     => ['nullable', 'string', 'max:120'],
        ]);

        // A partner chosen with no typed name inherits the partner's name.
        if (empty($data['supplier_name']) && ! empty($data['vendor_partner_id'])) {
            $data['supplier_name'] = VendorPartner::whereKey($data['vendor_partner_id'])->value('name');
        }

        return $data;
    }

    private function payload(ProjectMaterialOrder $o): array
    {
        return [
            'id'          => $o->id,
            'reference'   => $o->reference,
            'name'        => $o->name,
            'description' => $o->description,
            'status'      => $o->status,
            'due_date'    => $o->due_date?->toDateString(),
            'is_overdue'  => $o->is_overdue,
            'lpo_reference' => $o->lpo_reference,
            'supplier'    => $o->supplier ? ['id' => $o->supplier->id, 'name' => $o->supplier->name] : null,
            'supplier_name' => $o->supplier_name,
            'vendor_partner_id' => $o->vendor_partner_id,
            'trackers' => $o->trackers->map(fn (ProjectMaterialOrderTracker $t) => [
                'id' => $t->id, 'name' => $t->name, 'details' => $t->details,
                'happened_on' => $t->happened_on?->toDateString(),
                'by' => $t->relationLoaded('creator') ? $t->creator?->name : null,
            ])->values(),
            'updates' => $o->updates->map(fn (ProjectMaterialOrderUpdate $u) => [
                'id' => $u->id, 'latest_supplier_update' => $u->latest_supplier_update,
                'delivery_plan_status' => $u->delivery_plan_status,
                'by' => $u->relationLoaded('creator') ? $u->creator?->name : null,
                'at' => $u->created_at?->toDateTimeString(),
            ])->values(),
            'contacts' => $o->contacts->map(fn (ProjectMaterialOrderContact $c) => [
                'id' => $c->id, 'name' => $c->name, 'email' => $c->email, 'phone' => $c->phone, 'address' => $c->address,
            ])->values(),
            'documents' => $o->documents->map(fn (ProjectAttachment $a) => [
                'id' => $a->id, 'label' => $a->label, 'size_bytes' => $a->size_bytes,
            ])->values(),
        ];
    }
}
