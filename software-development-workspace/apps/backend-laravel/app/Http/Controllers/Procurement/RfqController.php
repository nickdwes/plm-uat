<?php

namespace App\Http\Controllers\Procurement;

use App\Http\Controllers\Controller;
use App\Models\Procurement\ProcurementRequest;
use App\Models\Procurement\Rfq;
use App\Services\Procurement\RfqService;
use App\Support\Authorization\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The sourcing round on a purchase: who was asked, what they quoted, how the
 * quotes compare, and who won.
 */
class RfqController extends Controller
{
    public function __construct(private RfqService $rfqs)
    {
    }

    public function show(Request $request, ProcurementRequest $procurement): JsonResponse
    {
        abort_unless($request->user()?->hasPermission(Permission::ASSETS_VIEW), 403);

        return response()->json(['data' => $this->payload($procurement)]);
    }

    public function open(Request $request, ProcurementRequest $procurement): JsonResponse
    {
        $this->authorizeWrite($request, $procurement);

        $data = $request->validate(['due_date' => ['nullable', 'date']]);

        try {
            $this->rfqs->open($procurement, $request->user()->id, $data['due_date'] ?? null);
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json(['data' => $this->payload($procurement->fresh())], 201);
    }

    public function invite(Request $request, ProcurementRequest $procurement): JsonResponse
    {
        $this->authorizeWrite($request, $procurement);
        $rfq = $this->requireRfq($procurement);

        $data = $request->validate([
            'vendor_partner_id' => ['required', 'integer',
                Rule::exists('vendor_partners', 'id')->where('organization_id', $procurement->organization_id)],
        ]);

        $this->rfqs->invite($rfq, $data['vendor_partner_id']);

        return response()->json(['data' => $this->payload($procurement->fresh())]);
    }

    public function storeQuotation(Request $request, ProcurementRequest $procurement): JsonResponse
    {
        $this->authorizeWrite($request, $procurement);
        $rfq = $this->requireRfq($procurement);

        $data = $request->validate([
            'vendor_partner_id' => ['required', 'integer',
                Rule::exists('vendor_partners', 'id')->where('organization_id', $procurement->organization_id)],
            'amount'          => ['required', 'numeric', 'min:0', 'max:9999999999999'],
            'currency'        => ['sometimes', 'string', 'size:3'],
            'lead_time_days'  => ['nullable', 'integer', 'min:0', 'max:3650'],
            'warranty_terms'  => ['nullable', 'string', 'max:255'],
            'payment_terms'   => ['nullable', 'string', 'max:255'],
            'delivery_terms'  => ['nullable', 'string', 'max:255'],
            'valid_until'     => ['nullable', 'date'],
            // The buyer's judgement, out of 100.
            'technical_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'vendor_score'    => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes'           => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->rfqs->recordQuotation($rfq, $data, $request->user()->id);
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json(['data' => $this->payload($procurement->fresh())], 201);
    }

    public function waive(Request $request, ProcurementRequest $procurement): JsonResponse
    {
        $this->authorizeWrite($request, $procurement);
        $rfq = $this->requireRfq($procurement);

        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $this->rfqs->waive($rfq, $data['reason'], $request->user()->id);

        return response()->json(['data' => $this->payload($procurement->fresh())]);
    }

    public function award(Request $request, ProcurementRequest $procurement): JsonResponse
    {
        $this->authorizeWrite($request, $procurement);
        $rfq = $this->requireRfq($procurement);

        $data = $request->validate([
            'quotation_id'  => ['required', 'integer'],
            'justification' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->rfqs->award($rfq, $data['quotation_id'], $request->user()->id, $data['justification'] ?? null);
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json(['data' => $this->payload($procurement->fresh())]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function requireRfq(ProcurementRequest $procurement): Rfq
    {
        $rfq = $procurement->rfq;
        abort_unless($rfq, 422, 'This purchase has not gone out to quote yet.');

        return $rfq;
    }

    /** Project-linked purchases authorize against the project, as elsewhere. */
    private function authorizeWrite(Request $request, ProcurementRequest $procurement): void
    {
        abort_if($procurement->isClosed(), 422, 'This purchase is closed.');

        if ($procurement->project) {
            $this->authorize('update', $procurement->project);

            return;
        }

        abort_unless($request->user()?->hasPermission(Permission::ASSETS_UPDATE), 403);
    }

    private function payload(ProcurementRequest $procurement): array
    {
        $rfq = $procurement->rfq()->with(['quotations.vendor', 'invitations.vendor', 'waivedBy:id,name'])->first();

        if (! $rfq) {
            return ['rfq' => null, 'may_award' => false, 'required_quotes' => Rfq::REQUIRED_QUOTES];
        }

        $quotes = $rfq->quotations->sortByDesc('total_score')->values();
        $best = $quotes->first();

        return [
            'rfq' => [
                'id'         => $rfq->id,
                'reference'  => $rfq->reference,
                'status'     => $rfq->status,
                'issue_date' => $rfq->issue_date?->toDateString(),
                'due_date'   => $rfq->due_date?->toDateString(),
                'compliance_met' => $rfq->compliance_met,
                'quote_count'    => $quotes->count(),
                'waiver_reason'  => $rfq->waiver_reason,
                'waived_by'      => $rfq->waivedBy?->name,
            ],
            'invitations' => $rfq->invitations->map(fn ($i) => [
                'id' => $i->id, 'vendor' => $i->vendor?->name, 'vendor_partner_id' => $i->vendor_partner_id,
                'status' => $i->status, 'responded_at' => $i->responded_at?->toDateTimeString(),
            ])->values(),
            'quotations' => $quotes->map(fn ($q) => [
                'id' => $q->id, 'vendor' => $q->vendor?->name, 'vendor_partner_id' => $q->vendor_partner_id,
                'amount' => (float) $q->amount, 'currency' => strtoupper($q->currency),
                'lead_time_days' => $q->lead_time_days,
                'warranty_terms' => $q->warranty_terms, 'payment_terms' => $q->payment_terms,
                'price_score' => $q->price_score !== null ? (float) $q->price_score : null,
                'delivery_score' => $q->delivery_score !== null ? (float) $q->delivery_score : null,
                'technical_score' => $q->technical_score !== null ? (float) $q->technical_score : null,
                'vendor_score' => $q->vendor_score !== null ? (float) $q->vendor_score : null,
                'total_score' => $q->total_score !== null ? (float) $q->total_score : null,
                'is_awarded' => $q->is_awarded,
                'recommended' => $best && $best->id === $q->id,
                'award_justification' => $q->award_justification,
            ])->values(),
            'may_award'       => $rfq->mayAward(),
            'required_quotes' => Rfq::REQUIRED_QUOTES,
            'awarded' => $procurement->awarded_vendor_id ? [
                'vendor_id' => $procurement->awarded_vendor_id,
                'amount'    => (float) $procurement->awarded_amount,
            ] : null,
        ];
    }
}
