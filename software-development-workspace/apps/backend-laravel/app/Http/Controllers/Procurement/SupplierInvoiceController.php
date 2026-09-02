<?php

namespace App\Http\Controllers\Procurement;

use App\Http\Controllers\Controller;
use App\Models\Procurement\ProcurementRequest;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Procurement\SupplierInvoice;
use App\Services\Procurement\InvoiceMatchService;
use App\Support\Authorization\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Supplier invoices on a purchase: the three-way match, the payment, and the
 * closure that locks the whole thing.
 */
class SupplierInvoiceController extends Controller
{
    public function __construct(private InvoiceMatchService $invoices)
    {
    }

    public function index(Request $request, ProcurementRequest $procurement): JsonResponse
    {
        abort_unless($request->user()?->hasPermission(Permission::ASSETS_VIEW), 403);

        return response()->json(['data' => $this->payload($procurement)]);
    }

    public function store(Request $request, ProcurementRequest $procurement, PurchaseOrder $order): JsonResponse
    {
        $this->authorizeWrite($request, $procurement);
        abort_unless($order->procurement_request_id === $procurement->id, 404);

        $data = $request->validate([
            'invoice_number' => ['nullable', 'string', 'max:120'],
            'invoice_date'   => ['nullable', 'date'],
            'due_date'       => ['nullable', 'date'],
            'amount'         => ['required', 'numeric', 'min:0', 'max:9999999999999'],
            'tax_amount'     => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'currency'       => ['sometimes', 'string', 'size:3'],
        ]);

        try {
            $this->invoices->record($order, $data, $request->user()->id);
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json(['data' => $this->payload($procurement->fresh())], 201);
    }

    /** Let a mismatched invoice through — finance only, and with a reason. */
    public function override(Request $request, ProcurementRequest $procurement, SupplierInvoice $invoice): JsonResponse
    {
        $this->authorizeWrite($request, $procurement);
        abort_unless($invoice->purchaseOrder?->procurement_request_id === $procurement->id, 404);
        abort_unless(
            $request->user()->hasPermission(Permission::PROCUREMENT_APPROVE_FINANCE),
            403,
            'Only finance may pass an invoice that failed the match.',
        );

        $data = $request->validate(['justification' => ['required', 'string', 'max:1000']]);

        try {
            $this->invoices->overrideException($invoice, $data['justification'], $request->user()->id);
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json(['data' => $this->payload($procurement->fresh())]);
    }

    public function pay(Request $request, ProcurementRequest $procurement, SupplierInvoice $invoice): JsonResponse
    {
        $this->authorizeWrite($request, $procurement);
        abort_unless($invoice->purchaseOrder?->procurement_request_id === $procurement->id, 404);
        abort_unless(
            $request->user()->hasPermission(Permission::PROCUREMENT_APPROVE_FINANCE),
            403,
            'Only finance may record a payment.',
        );

        $data = $request->validate([
            'paid_amount'       => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'paid_at'           => ['nullable', 'date'],
            'payment_reference' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $this->invoices->pay($invoice, $data, $request->user()->id);
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json(['data' => $this->payload($procurement->fresh())]);
    }

    /** Close the purchase — and lock it. */
    public function close(Request $request, ProcurementRequest $procurement): JsonResponse
    {
        $this->authorizeWrite($request, $procurement);

        try {
            $this->invoices->close($procurement, $request->user()->id);
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json(['data' => $this->payload($procurement->fresh())]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

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
        $poIds = PurchaseOrder::where('procurement_request_id', $procurement->id)->pluck('id');
        $invoices = SupplierInvoice::whereIn('purchase_order_id', $poIds)
            ->with(['vendor', 'overriddenBy:id,name'])->latest('id')->get();

        return [
            'invoices' => $invoices->map(fn (SupplierInvoice $i) => [
                'id'             => $i->id,
                'reference'      => $i->reference,
                'invoice_number' => $i->invoice_number,
                'vendor'         => $i->vendor?->name,
                'invoice_date'   => $i->invoice_date?->toDateString(),
                'due_date'       => $i->due_date?->toDateString(),
                'amount'         => (float) $i->amount,
                'tax_amount'     => (float) $i->tax_amount,
                'total_amount'   => (float) $i->total_amount,
                'currency'       => strtoupper($i->currency),
                'match' => [
                    'po'     => $i->matched_po,
                    'grn'    => $i->matched_grn,
                    'price'  => $i->matched_price,
                    'status' => $i->match_status,
                    'reason' => $i->exception_reason,
                    'overridden_by' => $i->overriddenBy?->name,
                    'override_justification' => $i->override_justification,
                ],
                'status'      => $i->status,
                'paid_amount' => (float) $i->paid_amount,
                'balance'     => $i->balance(),
                'paid_at'     => $i->paid_at?->toDateTimeString(),
                'payment_reference' => $i->payment_reference,
            ])->values(),
            'lifecycle_stage' => $procurement->lifecycle_stage,
            'closed'          => $procurement->isClosed(),
            'closed_at'       => $procurement->closed_at?->toDateTimeString(),
            'outstanding'     => round((float) $invoices->whereIn('status', SupplierInvoice::OPEN_STATUSES)->sum(fn ($i) => $i->balance()), 2),
            'paid_total'      => round((float) $invoices->sum('paid_amount'), 2),
        ];
    }
}
