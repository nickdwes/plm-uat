<?php

namespace App\Http\Controllers\Procurement;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Procurement\GoodsReceiptNote;
use App\Models\Procurement\ProcurementApproval;
use App\Models\Procurement\ProcurementQuotation;
use App\Models\Procurement\ProcurementRequest;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Procurement\Rfq;
use App\Models\Procurement\SupplierInvoice;
use App\Models\Training\VendorPartner;
use App\Services\Procurement\VendorPerformanceService;
use App\Support\Authorization\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What procurement looks like from above: where the money went, how long it
 * took to get there, and who it went to.
 *
 * Everything is read off the records the lifecycle already stamps. Nothing is
 * accumulated into a reporting table, so a corrected GRN or a reversed payment
 * shows up here immediately rather than at the next rebuild of something.
 */
class ProcurementDashboardController extends Controller
{
    public function __construct(private VendorPerformanceService $performance)
    {
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission(Permission::ASSETS_VIEW), 403);

        $orgId = app('current.organization_id');
        $filters = $request->validate([
            'from'       => ['nullable', 'date'],
            'to'         => ['nullable', 'date'],
            'project_id' => ['nullable', 'integer'],
            'category'   => ['nullable', 'string', 'max:60'],
            'vendor_id'  => ['nullable', 'integer'],
        ]);

        $requests = $this->requests($orgId, $filters);
        $orders = $this->orders($requests, $filters);
        $invoices = $this->invoices($orders);

        return response()->json(['data' => [
            'period'   => ['from' => $filters['from'] ?? null, 'to' => $filters['to'] ?? null],
            'spend'    => $this->spend($requests, $orders, $invoices),
            'savings'  => $this->savings($requests),
            'cycle'    => $this->cycleTimes($requests, $orders, $invoices),
            'pipeline' => $this->pipeline($requests),
            'compliance' => $this->compliance($requests, $invoices),
            'vendors'  => $this->performance->ranking($orgId, $filters['from'] ?? null, 10),
            'by_category' => $this->byCategory($requests),
        ]]);
    }

    /** One supplier's scorecard in full. */
    public function vendor(Request $request, VendorPartner $vendor): JsonResponse
    {
        // A vendor's performance answers to the vendor permission. It asked
        // for `assets.view` — a neighbouring permission from the epic this
        // endpoint was built in, which let asset readers rate suppliers and
        // kept the vendor screen's own audience out of its scorecard tab.
        abort_unless($request->user()?->hasPermission(Permission::VENDORS_VIEW), 403);

        $since = $request->validate(['from' => ['nullable', 'date']])['from'] ?? null;

        return response()->json(['data' => $this->performance->scorecard($vendor, $since)]);
    }

    /**
     * Everything that happened to one purchase, in order.
     *
     * Assembled from the stamps the lifecycle already leaves plus the central
     * audit trail — a timeline table would be a second copy of facts that are
     * already recorded, and would drift from them.
     */
    public function timeline(Request $request, ProcurementRequest $procurement): JsonResponse
    {
        abort_unless($request->user()?->hasPermission(Permission::ASSETS_VIEW), 403);

        $events = collect();

        $events->push($this->event($procurement->created_at, 'raised', "Request raised for {$procurement->item}", $procurement->creator?->name));

        if ($procurement->submitted_at) {
            $events->push($this->event($procurement->submitted_at, 'submitted', 'Submitted for approval'));
        }

        if ($procurement->budget_checked_at) {
            $events->push($this->event(
                $procurement->budget_checked_at,
                'budget_checked',
                match ($procurement->budget_result) {
                    ProcurementRequest::BUDGET_EXCEEDED => 'Budget check failed — over available budget',
                    ProcurementRequest::BUDGET_OVERRIDDEN => 'Budget exceeded, passed on a finance override',
                    ProcurementRequest::BUDGET_NONE => 'No project budget applies',
                    default => 'Checked against the project budget',
                },
            ));
        }

        foreach ($procurement->approvals()->with('decider:id,name')->orderBy('sequence')->get() as $approval) {
            if ($approval->decided_at) {
                $events->push($this->event(
                    $approval->decided_at,
                    $approval->status === 'rejected' ? 'rejected' : 'approved',
                    ucfirst(str_replace('_', ' ', $approval->stage)) . ' ' . $approval->status
                        . ($approval->comments ? ": {$approval->comments}" : ''),
                    $approval->decider?->name,
                ));
            }
        }

        $rfq = Rfq::where('procurement_request_id', $procurement->id)->first();
        if ($rfq) {
            $events->push($this->event($rfq->created_at, 'rfq', "Sourcing round {$rfq->reference} opened"));

            foreach (ProcurementQuotation::where('rfq_id', $rfq->id)->with('vendor')->get() as $quote) {
                $events->push($this->event(
                    $quote->created_at,
                    $quote->is_awarded ? 'awarded' : 'quoted',
                    $quote->is_awarded
                        ? "Awarded to {$quote->vendor?->name} at " . number_format((float) $quote->amount, 2)
                        : "Quote from {$quote->vendor?->name}: " . number_format((float) $quote->amount, 2),
                ));
            }

            if ($rfq->waiver_reason) {
                $events->push($this->event($rfq->updated_at, 'waiver', "Three-quote rule waived: {$rfq->waiver_reason}"));
            }
        }

        foreach (PurchaseOrder::where('procurement_request_id', $procurement->id)->with(['receipts', 'vendor'])->get() as $po) {
            if ($po->issued_at) {
                $events->push($this->event($po->issued_at, 'po_issued', "{$po->reference} issued to {$po->vendor?->name} for {$po->currency} " . number_format((float) $po->total_amount, 2)));
            }
            if ($po->accepted_at) {
                $events->push($this->event(
                    $po->accepted_at,
                    $po->status === PurchaseOrder::STATUS_DECLINED ? 'po_declined' : 'po_accepted',
                    $po->status === PurchaseOrder::STATUS_DECLINED
                        ? 'Supplier declined the order' . ($po->acceptance_note ? ": {$po->acceptance_note}" : '')
                        : 'Supplier accepted the order',
                ));
            }

            foreach ($po->receipts as $grn) {
                $events->push($this->event(
                    $grn->received_at,
                    'received',
                    "{$grn->reference}: {$grn->quantity_received} received"
                        . ($grn->on_time === false ? ' (late)' : '')
                        . ($grn->quality_status !== 'accepted' ? " — quality {$grn->quality_status}" : ''),
                    $grn->receiver?->name,
                ));
            }

            foreach (SupplierInvoice::where('purchase_order_id', $po->id)->with('overriddenBy:id,name')->get() as $invoice) {
                $events->push($this->event($invoice->created_at, 'invoiced', "{$invoice->reference} recorded for {$invoice->currency} " . number_format((float) $invoice->total_amount, 2)
                    . ($invoice->match_status === 'exception' ? " — match exception: {$invoice->exception_reason}" : ' — matched')));

                if ($invoice->exception_overridden_by) {
                    $events->push($this->event($invoice->updated_at, 'override', "Match exception overridden: {$invoice->override_justification}", $invoice->overriddenBy?->name));
                }

                if ($invoice->paid_at) {
                    $events->push($this->event($invoice->paid_at, 'paid', "Paid {$invoice->currency} " . number_format((float) $invoice->paid_amount, 2)
                        . ($invoice->payment_reference ? " ({$invoice->payment_reference})" : '')));
                }
            }
        }

        if ($procurement->closed_at) {
            $events->push($this->event($procurement->closed_at, 'closed', 'Purchase closed and locked'));
        }

        return response()->json(['data' => [
            'reference' => $procurement->reference,
            'item'      => $procurement->item,
            'stage'     => $procurement->lifecycle_stage,
            'events'    => $events->filter(fn ($e) => $e['at'] !== null)->sortBy('at')->values(),
            'audit'     => $this->auditTail($procurement),
        ]]);
    }

    // ── the numbers ──────────────────────────────────────────────────────────

    private function spend(Collection $requests, Collection $orders, Collection $invoices): array
    {
        $paid = $invoices->where('status', SupplierInvoice::STATUS_PAID);
        $committed = $requests
            ->whereNotIn('lifecycle_stage', ['paid', 'closed', 'cancelled', 'rejected', 'draft'])
            ->where('approval_status', 'approved')
            ->sum(fn (ProcurementRequest $r) => $r->committedAmount());

        return [
            'requested'  => round((float) $requests->sum(fn (ProcurementRequest $r) => (float) $r->estimated_cost), 2),
            'ordered'    => round((float) $orders->whereNotIn('status', [PurchaseOrder::STATUS_CANCELLED, PurchaseOrder::STATUS_DECLINED])->sum('total_amount'), 2),
            'invoiced'   => round((float) $invoices->where('status', '!=', SupplierInvoice::STATUS_CANCELLED)->sum('total_amount'), 2),
            'paid'       => round((float) $paid->sum('paid_amount'), 2),
            'committed'  => round((float) $committed, 2),
            'outstanding' => round((float) $invoices->whereIn('status', SupplierInvoice::OPEN_STATUSES)->sum(fn (SupplierInvoice $i) => $i->balance()), 2),
        ];
    }

    /**
     * What sourcing saved: the gap between what a purchase was expected to
     * cost and what it was actually awarded at. Only awarded requests can say
     * anything about this, and a negative number is a real answer — it means
     * the estimate was optimistic, which is worth seeing rather than hiding.
     */
    private function savings(Collection $requests): array
    {
        $awarded = $requests->filter(fn (ProcurementRequest $r) => $r->awarded_amount !== null && $r->estimated_cost !== null);

        $estimated = (float) $awarded->sum(fn (ProcurementRequest $r) => (float) $r->estimated_cost);
        $actual = (float) $awarded->sum(fn (ProcurementRequest $r) => (float) $r->awarded_amount);

        return [
            'awarded_count' => $awarded->count(),
            'estimated'     => round($estimated, 2),
            'awarded'       => round($actual, 2),
            'saved'         => round($estimated - $actual, 2),
            'saved_percent' => $estimated > 0 ? round(($estimated - $actual) / $estimated * 100, 1) : null,
        ];
    }

    /**
     * How long each leg of the journey takes, in days. Medians would be
     * steadier, but with these volumes an average that anyone can reconcile
     * against the records by hand is the more useful answer.
     */
    private function cycleTimes(Collection $requests, Collection $orders, Collection $invoices): array
    {
        $approval = [];
        foreach ($requests as $r) {
            $decided = ProcurementApproval::where('procurement_request_id', $r->id)
                ->whereNotNull('decided_at')->orderByDesc('decided_at')->first();

            if ($r->submitted_at && $decided && $r->approval_status === 'approved') {
                $approval[] = $r->submitted_at->diffInDays($decided->decided_at);
            }
        }

        $toOrder = [];
        $toDeliver = [];
        foreach ($orders as $o) {
            $request = $requests->firstWhere('id', $o->procurement_request_id);
            if ($request?->submitted_at && $o->issued_at) {
                $toOrder[] = $request->submitted_at->diffInDays($o->issued_at);
            }

            $last = $o->receipts->sortByDesc('received_at')->first();
            if ($o->issued_at && $last?->received_at) {
                $toDeliver[] = $o->issued_at->diffInDays($last->received_at);
            }
        }

        $toPay = [];
        foreach ($invoices->whereNotNull('paid_at') as $i) {
            if ($i->created_at) {
                $toPay[] = $i->created_at->diffInDays($i->paid_at);
            }
        }

        $end = [];
        foreach ($requests->whereNotNull('closed_at') as $r) {
            if ($r->submitted_at) {
                $end[] = $r->submitted_at->diffInDays($r->closed_at);
            }
        }

        return [
            'submit_to_approve'  => $this->mean($approval),
            'approve_to_order'   => $this->mean($toOrder),
            'order_to_delivery'  => $this->mean($toDeliver),
            'invoice_to_payment' => $this->mean($toPay),
            'end_to_end'         => $this->mean($end),
        ];
    }

    /** How many purchases are sitting at each stage right now. */
    private function pipeline(Collection $requests): array
    {
        $counts = [];
        foreach (ProcurementRequest::LIFECYCLE_STAGES as $stage) {
            $counts[$stage] = $requests->where('lifecycle_stage', $stage)->count();
        }

        return [
            'by_stage'          => $counts,
            'awaiting_approval' => $requests->where('approval_status', 'pending')->whereNotNull('submitted_at')->count(),
            'open'              => $requests->whereNotIn('lifecycle_stage', ['closed', 'cancelled', 'rejected'])->count(),
            'closed'            => $requests->where('lifecycle_stage', 'closed')->count(),
        ];
    }

    /** Where the governance rules were met, and where they were waived. */
    private function compliance(Collection $requests, Collection $invoices): array
    {
        $rfqs = Rfq::whereIn('procurement_request_id', $requests->pluck('id'))->get();

        return [
            'sourcing_rounds'   => $rfqs->count(),
            'three_quote_met'   => $rfqs->where('compliance_met', true)->count(),
            'waived'            => $rfqs->whereNotNull('waiver_reason')->count(),
            'budget_overrides'  => $requests->where('budget_result', ProcurementRequest::BUDGET_OVERRIDDEN)->count(),
            'invoice_exceptions' => $invoices->where('match_status', 'exception')->count(),
            'exceptions_overridden' => $invoices->whereNotNull('exception_overridden_by')->count(),
        ];
    }

    private function byCategory(Collection $requests): array
    {
        return $requests->groupBy(fn (ProcurementRequest $r) => $r->category ?: 'uncategorised')
            ->map(fn (Collection $g, string $category) => [
                'category'  => $category,
                'count'     => $g->count(),
                'estimated' => round((float) $g->sum(fn (ProcurementRequest $r) => (float) $r->estimated_cost), 2),
                'committed' => round((float) $g->sum(fn (ProcurementRequest $r) => $r->committedAmount()), 2),
            ])->values()->sortByDesc('estimated')->values()->all();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function requests(int $orgId, array $f): Collection
    {
        return ProcurementRequest::where('organization_id', $orgId)
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', Carbon::parse($v)->startOfDay()))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', Carbon::parse($v)->endOfDay()))
            ->when($f['project_id'] ?? null, fn ($q, $v) => $q->where('project_id', $v))
            ->when($f['category'] ?? null, fn ($q, $v) => $q->where('category', $v))
            ->when($f['vendor_id'] ?? null, fn ($q, $v) => $q->where('vendor_partner_id', $v))
            ->get();
    }

    private function orders(Collection $requests, array $f): Collection
    {
        return $requests->isEmpty() ? collect() : PurchaseOrder::whereIn('procurement_request_id', $requests->pluck('id'))
            ->when($f['vendor_id'] ?? null, fn ($q, $v) => $q->where('vendor_partner_id', $v))
            ->with('receipts')->get();
    }

    private function invoices(Collection $orders): Collection
    {
        return $orders->isEmpty() ? collect() : SupplierInvoice::whereIn('purchase_order_id', $orders->pluck('id'))->get();
    }

    private function mean(array $values): ?float
    {
        return $values === [] ? null : round(array_sum($values) / count($values), 1);
    }

    private function event(?Carbon $at, string $kind, string $label, ?string $actor = null): array
    {
        return ['at' => $at?->toDateTimeString(), 'kind' => $kind, 'label' => $label, 'actor' => $actor];
    }

    /**
     * The raw audit rows behind this purchase, for anyone who needs the
     * before/after rather than the narrative.
     */
    private function auditTail(ProcurementRequest $procurement): array
    {
        $poIds = PurchaseOrder::where('procurement_request_id', $procurement->id)->pluck('id');

        return AuditLog::where(function ($q) use ($procurement, $poIds) {
            $q->where(fn ($w) => $w->where('auditable_type', ProcurementRequest::class)->where('auditable_id', $procurement->id))
              ->orWhere(fn ($w) => $w->where('auditable_type', PurchaseOrder::class)->whereIn('auditable_id', $poIds));
        })
            ->latest('created_at')->limit(50)->get()
            ->map(fn (AuditLog $a) => [
                'at'     => $a->created_at?->toDateTimeString(),
                'action' => $a->action,
                'actor'  => $a->actor_name,
                'what'   => $a->description,
            ])->all();
    }
}
