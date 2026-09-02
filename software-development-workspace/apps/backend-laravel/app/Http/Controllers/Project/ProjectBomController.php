<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project\Project;
use App\Models\Project\ProjectBom;
use App\Models\Project\ProjectBomItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A project's Bill of Materials — the priced lines a won deal delivers, edited
 * while the BOM is draft and frozen once approved. Approving is the trigger the
 * order-lifecycle engine (a later increment) generates order records from.
 */
class ProjectBomController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json($this->payload($this->activeBom($project)));
    }

    public function storeItem(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $bom = $this->draftBom($project);
        $data = $this->validateItem($request, $project, creating: true);

        $bom->items()->create($data + [
            'organization_id' => $project->organization_id,
            'project_id'      => $project->id,
            'line_no'         => (int) $bom->items()->max('line_no') + 1,
        ]);

        return response()->json($this->payload($bom->fresh()), 201);
    }

    public function updateItem(Request $request, Project $project, ProjectBomItem $item): JsonResponse
    {
        $this->authorize('update', $project);
        $bom = $this->requireDraftFor($project, $item);

        $item->update($this->validateItem($request, $project, creating: false));

        return response()->json($this->payload($bom->fresh()));
    }

    public function destroyItem(Project $project, ProjectBomItem $item): JsonResponse
    {
        $this->authorize('update', $project);
        $bom = $this->requireDraftFor($project, $item);

        $item->delete();

        return response()->json($this->payload($bom->fresh()));
    }

    /**
     * Import lines from a CSV. The header row is matched loosely (case- and
     * space-insensitive) so an export from a spreadsheet drops in without
     * renaming columns. Unknown categories fall back to 'hardware' and are
     * reported so nothing is silently miscategorised.
     */
    public function import(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);

        $bom = $this->draftBom($project);
        $rows = array_map('str_getcsv', file($request->file('file')->getRealPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        if (! $rows) {
            return response()->json($this->payload($bom->fresh()) + ['imported' => 0]);
        }

        $header = array_map(fn ($h) => $this->normalizeKey($h), array_shift($rows));
        $line = (int) $bom->items()->max('line_no');
        $imported = 0;

        foreach ($rows as $row) {
            $cells = array_combine($header, array_pad(array_slice($row, 0, count($header)), count($header), null));
            $description = trim((string) $this->cell($cells, ['description', 'item', 'itemdescription', 'name']));
            if ($description === '') {
                continue;
            }

            $category = $this->normalizeKey((string) $this->cell($cells, ['category', 'type']));
            $bom->items()->create([
                'organization_id' => $project->organization_id,
                'project_id'      => $project->id,
                'line_no'         => ++$line,
                'category'        => in_array($category, ProjectBomItem::CATEGORIES, true) ? $category : 'hardware',
                'description'     => mb_substr($description, 0, 300),
                'part_number'     => $this->cell($cells, ['partnumber', 'part', 'sku', 'partno']) ?: null,
                'supplier_name'   => $this->cell($cells, ['supplier', 'vendor', 'suppliername']) ?: null,
                'quantity'        => (float) ($this->cell($cells, ['quantity', 'qty']) ?: 1) ?: 1,
                'unit'            => $this->cell($cells, ['unit', 'uom']) ?: null,
                'unit_cost'       => (float) $this->cell($cells, ['unitcost', 'cost', 'buyprice']),
                'selling_price'   => (float) $this->cell($cells, ['sellingprice', 'sell', 'price', 'unitprice']),
                'currency'        => strtoupper((string) ($this->cell($cells, ['currency', 'ccy']) ?: 'KES')) ?: 'KES',
            ]);
            $imported++;
        }

        return response()->json($this->payload($bom->fresh()) + ['imported' => $imported]);
    }

    /**
     * Approve the BOM — records who signed off, freezes edits, and generates
     * the order records (idempotent, so re-approving does not duplicate).
     */
    public function approve(Request $request, Project $project, \App\Services\Project\OrderGenerator $generator, \App\Services\Project\OrderReadiness $readiness): JsonResponse
    {
        $this->authorize('update', $project);

        $bom = $this->activeBom($project);
        abort_if($bom->items()->count() === 0, 422, 'Add at least one line before approving the BOM.');

        $bom->update([
            'status'      => ProjectBom::STATUS_APPROVED,
            'approved_at' => now(),
            'approved_by' => $request->user()->id,
        ]);

        // Approving freezes the lines; orders are only generated once the
        // project's order-release gate is satisfied. If it is not yet, the BOM
        // waits and the gate releases it later.
        $ready = $readiness->ready($project);
        $generated = $ready ? $generator->generate($bom, $request->user()->id) : null;

        return response()->json($this->payload($bom->fresh()) + [
            'generated' => $generated,
            'held'      => ! $ready,
            'readiness' => $readiness->for($project),
        ]);
    }

    /** Reopen an approved BOM for editing. */
    public function reopen(Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $bom = $this->activeBom($project);
        $bom->update(['status' => ProjectBom::STATUS_DRAFT, 'approved_at' => null, 'approved_by' => null]);

        return response()->json($this->payload($bom->fresh()));
    }

    // ── helpers ────────────────────────────────────────────────────────────

    /** The current BOM (draft or approved), created as a draft if none exists. */
    private function activeBom(Project $project): ProjectBom
    {
        return $project->boms()->where('status', '!=', ProjectBom::STATUS_SUPERSEDED)->latest('id')->first()
            ?? $project->boms()->create([
                'organization_id' => $project->organization_id,
                'created_by'      => request()->user()?->id,
            ]);
    }

    private function draftBom(Project $project): ProjectBom
    {
        $bom = $this->activeBom($project);
        abort_if($bom->isApproved(), 422, 'The BOM is approved. Reopen it to change lines.');

        return $bom;
    }

    private function requireDraftFor(Project $project, ProjectBomItem $item): ProjectBom
    {
        abort_unless($item->project_id === $project->id, 404);
        $bom = $this->activeBom($project);
        abort_unless($item->project_bom_id === $bom->id, 404);
        abort_if($bom->isApproved(), 422, 'The BOM is approved. Reopen it to change lines.');

        return $bom;
    }

    private function validateItem(Request $request, Project $project, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'category'      => [$req, Rule::in(ProjectBomItem::CATEGORIES)],
            'description'   => [$req, 'string', 'max:300'],
            'part_number'   => ['nullable', 'string', 'max:120'],
            'vendor_partner_id' => ['nullable', 'integer', Rule::exists('vendor_partners', 'id')->where('organization_id', $project->organization_id)],
            'supplier_name' => ['nullable', 'string', 'max:200'],
            'quantity'      => ['sometimes', 'numeric', 'min:0', 'max:9999999'],
            'unit'          => ['nullable', 'string', 'max:40'],
            'unit_cost'     => ['sometimes', 'numeric', 'min:0', 'max:9999999999999'],
            'selling_price' => ['sometimes', 'numeric', 'min:0', 'max:9999999999999'],
            'currency'      => ['sometimes', 'string', 'size:3'],
            'expected_delivery_date' => ['nullable', 'date'],
            'phase_id'      => ['nullable', 'integer', Rule::exists('project_phases', 'id')->where('project_id', $project->id)],
            'notes'         => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function normalizeKey(string $s): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', $s));
    }

    /** First non-empty value among the candidate normalized header keys. */
    private function cell(array $cells, array $keys): ?string
    {
        foreach ($keys as $k) {
            if (isset($cells[$k]) && trim((string) $cells[$k]) !== '') {
                return trim((string) $cells[$k]);
            }
        }

        return null;
    }

    private function payload(ProjectBom $bom): array
    {
        $items = $bom->items()->with(['vendorPartner:id,name', 'phase:id,name'])->get();

        $sum = fn (callable $f) => round((float) $items->sum($f), 2);

        return [
            'bom' => [
                'id'          => $bom->id,
                'version'     => $bom->version,
                'status'      => $bom->status,
                'approved_at' => $bom->approved_at?->toDateTimeString(),
                'approved_by' => $bom->approver?->name,
                'notes'       => $bom->notes,
            ],
            'items' => $items->map(fn (ProjectBomItem $i) => [
                'id'            => $i->id,
                'line_no'       => $i->line_no,
                'category'      => $i->category,
                'description'   => $i->description,
                'part_number'   => $i->part_number,
                'vendor_partner_id' => $i->vendor_partner_id,
                'supplier'      => $i->vendorPartner?->name ?? $i->supplier_name,
                'quantity'      => (float) $i->quantity,
                'unit'          => $i->unit,
                'unit_cost'     => (float) $i->unit_cost,
                'selling_price' => (float) $i->selling_price,
                'currency'      => strtoupper($i->currency),
                'margin'        => $i->margin,
                'order_value'   => $i->order_value,
                'expected_delivery_date' => $i->expected_delivery_date?->toDateString(),
                'phase'         => $i->phase?->name,
                'notes'         => $i->notes,
            ])->values(),
            'summary' => [
                'lines'       => $items->count(),
                'total_cost'  => $sum(fn ($i) => (float) $i->unit_cost * (float) $i->quantity),
                'total_value' => $sum(fn ($i) => $i->order_value),
                'total_margin' => $sum(fn ($i) => $i->margin),
                'by_category' => $items->groupBy('category')->map->count(),
            ],
            'options' => ['categories' => ProjectBomItem::CATEGORIES],
        ];
    }
}
