<?php

namespace App\Http\Controllers\Asset;

use App\Http\Controllers\Controller;
use App\Models\Asset\Asset;
use App\Models\Asset\AssetDisposal;
use App\Models\Asset\AssetIssue;
use App\Models\Asset\AssetMaintenance;
use App\Models\Asset\AssetRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The lists behind the Assets tabs that had no way of being read.
 *
 * `asset_requests` and `asset_issues` have existed with models, messages and
 * escalation for weeks, and no route ever exposed them — a request could be
 * written by a seeder and never seen by anybody. Same for disposals, which were
 * added days ago.
 *
 * One controller rather than three, because these are three lists over the same
 * register rather than three domains. Splitting them would produce three files
 * that each do one query.
 */
class AssetRegisterController extends Controller
{
    /**
     * Requests for equipment somebody does not yet have.
     *
     * The designs' tab shows every request across the organisation, which is a
     * manager's view. Somebody looking at their own is `mine=1` — the same
     * endpoint, because two endpoints over one table drift.
     */
    public function requests(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Asset::class);

        $q = AssetRequest::query()
            ->with(['employee:id,first_name,last_name', 'asset:id,name,asset_tag'])
            ->latest('id');

        if ($request->boolean('mine')) {
            $q->where('employee_id', $request->user()->employeeId());
        }

        if ($status = $request->string('status')->toString()) {
            $q->where('status', $status);
        }

        return response()->json([
            'data' => $q->paginate((int) $request->input('per_page', 25))->through(fn (AssetRequest $r) => [
                'id'           => $r->id,
                'reference'    => $r->request_number,
                'employee'     => $r->employee?->full_name,
                'item'         => $r->item,
                'category'     => $r->category,
                'quantity'     => $r->quantity,
                'urgency'      => $r->urgency,
                'status'       => $r->status,
                'reason'       => $r->reason,
                'asset'        => $r->asset?->name,
                'submitted_on' => $r->created_at?->toDateString(),
                'decided_on'   => $r->approved_at?->toDateString(),
                'decision_note' => $r->decision_note,
            ]),
        ]);
    }

    /** Faults reported against equipment somebody holds. */
    public function issues(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Asset::class);

        $q = AssetIssue::query()
            ->with(['asset:id,name,asset_tag,serial_number'])
            ->latest('id');

        if ($request->boolean('open')) {
            $q->whereNull('resolved_at');
        }

        if ($assetId = $request->integer('asset_id')) {
            $q->where('asset_id', $assetId);
        }

        return response()->json([
            'data' => $q->paginate((int) $request->input('per_page', 25))->through(fn (AssetIssue $i) => [
                'id'          => $i->id,
                'reference'   => $i->ticket_number,
                'asset'       => $i->asset?->name,
                'asset_tag'   => $i->asset?->asset_tag,
                'issue_type'  => $i->issue_type,
                'description' => $i->description,
                'priority'    => $i->priority,
                'status'      => $i->status,
                'reported_on' => $i->created_at?->toDateString(),
                'resolved_on' => $i->resolved_at?->toDateString(),
            ]),
        ]);
    }

    /**
     * Servicing — what is waiting, what is being done, what it cost.
     *
     * Open work first. A maintenance list sorted by date puts last year's
     * completed services above the repair blocking somebody today.
     */
    public function maintenance(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Asset::class);

        $q = AssetMaintenance::query()
            ->with(['asset:id,name,asset_tag', 'vendor:id,name', 'technician:id,first_name,last_name'])
            ->orderByRaw("CASE WHEN status IN ('completed','cancelled') THEN 1 ELSE 0 END")
            ->orderByRaw('COALESCE(scheduled_for, performed_on) DESC NULLS LAST');

        if ($request->boolean('open')) {
            $q->open();
        }

        if ($assetId = $request->integer('asset_id')) {
            $q->where('asset_id', $assetId);
        }

        return response()->json([
            'data' => $q->paginate((int) $request->input('per_page', 25))->through(fn (AssetMaintenance $m) => [
                'id'         => $m->id,
                'reference'  => 'MT-' . str_pad((string) $m->id, 4, '0', STR_PAD_LEFT),
                'asset'      => $m->asset?->name,
                'asset_tag'  => $m->asset?->asset_tag,
                'type'       => $m->type,
                'reason'     => $m->reason,
                'status'     => $m->status,
                'scheduled_for' => $m->scheduled_for?->toDateString(),
                'performed_on'  => $m->performed_on?->toDateString(),
                'cost'       => $m->cost,
                'vendor'     => $m->vendor?->name,
                'technician' => $m->technician?->full_name,
                // Where a repair began as a fault report, the two are one story.
                'issue_id'   => $m->asset_issue_id,
            ]),
        ]);
    }

    /**
     * What has left the register, and how.
     *
     * Every column here is on the asset or the disposal record — but the
     * disposal record is why this is a list rather than a filter: it carries
     * who authorised it and whether it needed a licensed handler, and neither
     * is derivable from a retired flag.
     */
    public function disposals(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Asset::class);

        $q = AssetDisposal::query()
            ->with(['asset:id,name,asset_tag,serial_number,category,purchase_date'])
            ->latest('disposed_on');

        return response()->json([
            'data' => $q->paginate((int) $request->input('per_page', 25))->through(fn (AssetDisposal $d) => [
                'id'            => $d->id,
                'asset'         => $d->asset?->name,
                'asset_tag'     => $d->asset?->asset_tag,
                'serial_number' => $d->asset?->serial_number,
                'category'      => $d->asset?->category,
                'purchase_date' => $d->asset?->purchase_date?->toDateString(),
                'disposed_on'   => $d->disposed_on?->toDateString(),
                'method'        => $d->method,
                // Shown in the list rather than buried in the record: an item
                // that needed a licensed handler and has no certificate is the
                // one an auditor asks about.
                'requires_safe_disposal'  => (bool) $d->requires_safe_disposal,
                'certificate_received_on' => $d->certificate_received_on?->toDateString(),
                'proceeds'      => $d->proceeds,
            ]),
        ]);
    }
}
