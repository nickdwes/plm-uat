<?php

namespace App\Http\Controllers\Procurement;

use App\Http\Controllers\Controller;
use App\Models\Procurement\GoodsReceiptNote;
use App\Models\Procurement\ProcurementRequest;
use App\Models\Procurement\PurchaseOrder;
use App\Services\Procurement\PurchaseOrderService;
use App\Support\Authorization\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The order placed with the supplier, the supplier's answer, and what arrives
 * against it.
 */
class PurchaseOrderController extends Controller
{
    public function __construct(private PurchaseOrderService $orders)
    {
    }

    public function show(Request $request, ProcurementRequest $procurement): JsonResponse
    {
        abort_unless($request->user()?->hasPermission(Permission::ASSETS_VIEW), 403);

        return response()->json(['data' => $this->payload($procurement)]);
    }

    public function store(Request $request, ProcurementRequest $procurement): JsonResponse
    {
        $this->authorizeWrite($request, $procurement);

        $data = $request->validate([
            'vendor_partner_id' => ['nullable', 'integer',
                Rule::exists('vendor_partners', 'id')->where('organization_id', $procurement->organization_id)],
            'amount'           => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'tax_rate'         => ['nullable', 'numeric', 'min:0', 'max:100'],
            'currency'         => ['sometimes', 'string', 'size:3'],
            'quantity_ordered' => ['nullable', 'numeric', 'min:0.01'],
            'payment_terms'    => ['nullable', 'string', 'max:255'],
            'delivery_terms'   => ['nullable', 'string', 'max:255'],
            'expected_delivery_date' => ['nullable', 'date'],
        ]);

        try {
            $this->orders->issue($procurement, $data, $request->user()->id);
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json(['data' => $this->payload($procurement->fresh())], 201);
    }

    /** The supplier accepts or declines. */
    public function respond(Request $request, ProcurementRequest $procurement, PurchaseOrder $order): JsonResponse
    {
        $this->authorizeWrite($request, $procurement);
        abort_unless($order->procurement_request_id === $procurement->id, 404);

        $data = $request->validate([
            'accepted' => ['required', 'boolean'],
            'note'     => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->orders->respond($order, (bool) $data['accepted'], $data['note'] ?? null);
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json(['data' => $this->payload($procurement->fresh())]);
    }

    /** Record a delivery against the order. */
    public function receive(Request $request, ProcurementRequest $procurement, PurchaseOrder $order): JsonResponse
    {
        $this->authorizeWrite($request, $procurement);
        abort_unless($order->procurement_request_id === $procurement->id, 404);

        $data = $request->validate([
            'quantity_received' => ['required', 'numeric', 'min:0.01'],
            'quality_status'    => ['sometimes', Rule::in(GoodsReceiptNote::QUALITY)],
            'quality_notes'     => ['nullable', 'string', 'max:2000'],
            'received_at'       => ['nullable', 'date'],
        ]);

        try {
            $this->orders->receive($order, $data, $request->user()->id);
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json(['data' => $this->payload($procurement->fresh())], 201);
    }

    public function cancel(Request $request, ProcurementRequest $procurement, PurchaseOrder $order): JsonResponse
    {
        $this->authorizeWrite($request, $procurement);
        abort_unless($order->procurement_request_id === $procurement->id, 404);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        try {
            $this->orders->cancel($order, $data['reason'] ?? null);
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
        $orders = PurchaseOrder::where('procurement_request_id', $procurement->id)
            ->with(['vendor', 'receipts.receiver:id,name', 'materialOrder:id,reference'])
            ->latest('id')->get();

        return [
            'orders' => $orders->map(fn (PurchaseOrder $po) => [
                'id'        => $po->id,
                'reference' => $po->reference,
                'vendor'    => $po->vendor?->name,
                'status'    => $po->status,
                'amount'    => (float) $po->amount,
                'tax_rate'  => (float) $po->tax_rate,
                'tax_amount' => (float) $po->tax_amount,
                'total_amount' => (float) $po->total_amount,
                'currency'  => strtoupper($po->currency),
                'quantity_ordered'  => (float) $po->quantity_ordered,
                'quantity_received' => $po->quantityReceived(),
                'outstanding'       => round((float) $po->quantity_ordered - $po->quantityReceived(), 2),
                'payment_terms'  => $po->payment_terms,
                'delivery_terms' => $po->delivery_terms,
                'expected_delivery_date' => $po->expected_delivery_date?->toDateString(),
                'issued_at'      => $po->issued_at?->toDateTimeString(),
                'accepted_at'    => $po->accepted_at?->toDateTimeString(),
                'acceptance_note' => $po->acceptance_note,
                'material_order' => $po->materialOrder?->reference,
                'receipts' => $po->receipts->map(fn (GoodsReceiptNote $g) => [
                    'id' => $g->id, 'reference' => $g->reference,
                    'quantity_received' => (float) $g->quantity_received,
                    'variance' => (float) $g->variance,
                    'quality_status' => $g->quality_status, 'quality_notes' => $g->quality_notes,
                    'on_time' => $g->on_time,
                    'received_by' => $g->receiver?->name,
                    'received_at' => $g->received_at?->toDateTimeString(),
                ])->values(),
            ])->values(),
            'has_open_order' => $orders->contains(fn ($po) => $po->isOpen() || $po->status === PurchaseOrder::STATUS_RECEIVED),
        ];
    }
}
