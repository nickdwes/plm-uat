<?php

namespace App\Services\Procurement;

use App\Models\Procurement\GoodsReceiptNote;
use App\Models\Procurement\ProcurementRequest;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Project\ProjectMaterialOrder;
use App\Models\Project\ProjectOrder;
use App\Support\Account\ReferenceSequence;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Placing the order and receiving against it.
 *
 * One live purchase order per request: these are single-line purchases, so a
 * second open order against the same request means somebody has ordered twice.
 * Cancelled and declined orders stay on the record and a replacement may be
 * issued — what is refused is two orders being live at once.
 *
 * Receipts drive the order's status rather than the other way round. A partial
 * delivery leaves it partially received until the quantities add up, so nobody
 * marks an order complete while half of it is still on a lorry.
 */
class PurchaseOrderService
{
    /** Issue the order for an awarded (or waived, directly-sourced) purchase. */
    public function issue(ProcurementRequest $request, array $data, ?int $actorId = null): PurchaseOrder
    {
        if ($request->approval_status !== 'approved') {
            throw new RuntimeException('The purchase must be approved before an order is placed.');
        }

        if ($this->openOrderFor($request)) {
            throw new RuntimeException('This purchase already has a live order. Cancel it before issuing another.');
        }

        $vendorId = $data['vendor_partner_id'] ?? $request->awarded_vendor_id ?? $request->vendor_partner_id;
        if (! $vendorId) {
            throw new RuntimeException('Award the purchase or name a supplier before issuing the order.');
        }

        $amount = (float) ($data['amount'] ?? $request->awarded_amount ?? $request->estimated_cost ?? 0);
        $taxRate = (float) ($data['tax_rate'] ?? 0);
        $taxAmount = round($amount * $taxRate / 100, 2);

        return DB::transaction(function () use ($request, $data, $vendorId, $amount, $taxRate, $taxAmount, $actorId) {
            $po = PurchaseOrder::create([
                'organization_id'        => $request->organization_id,
                'procurement_request_id' => $request->id,
                'quotation_id'           => $data['quotation_id'] ?? $request->rfq?->quotations()->where('is_awarded', true)->value('id'),
                'vendor_partner_id'      => $vendorId,
                'project_id'             => $request->project_id,
                'reference'              => ReferenceSequence::next('PO', 'purchase_orders', $request->organization_id),
                'amount'                 => $amount,
                'tax_rate'               => $taxRate,
                'tax_amount'             => $taxAmount,
                'total_amount'           => round($amount + $taxAmount, 2),
                'currency'               => strtoupper($data['currency'] ?? $request->currency ?? 'KES'),
                'quantity_ordered'       => $data['quantity_ordered'] ?? $request->quantity ?? 1,
                'payment_terms'          => $data['payment_terms'] ?? null,
                'delivery_terms'         => $data['delivery_terms'] ?? null,
                'expected_delivery_date' => $data['expected_delivery_date'] ?? $request->required_by?->toDateString(),
                'status'                 => PurchaseOrder::STATUS_ISSUED,
                'issued_at'              => now(),
                'created_by'             => $actorId,
            ]);

            $request->update(['lifecycle_stage' => 'po_issued', 'status' => 'ordered']);

            $this->bridgeToMaterialOrder($po);
            $this->markSourceOrderReleased($request);

            return $po->fresh();
        });
    }

    /** The supplier's answer. Declining frees the request for another order. */
    public function respond(PurchaseOrder $po, bool $accepted, ?string $note = null): PurchaseOrder
    {
        if (! in_array($po->status, [PurchaseOrder::STATUS_ISSUED], true)) {
            throw new RuntimeException('Only an issued order is awaiting the supplier.');
        }

        $po->update([
            'status'          => $accepted ? PurchaseOrder::STATUS_ACCEPTED : PurchaseOrder::STATUS_DECLINED,
            'accepted_at'     => $accepted ? now() : null,
            'acceptance_note' => $note,
        ]);

        if (! $accepted) {
            // The purchase is back to needing a supplier, not back to needing
            // approval — the money was already agreed.
            $po->request?->update(['lifecycle_stage' => 'awarded', 'status' => 'requested']);
        }

        $this->syncMaterialOrder($po);

        return $po->fresh();
    }

    /**
     * Record a delivery. Partial deliveries are normal, so the quantity
     * decides the order's status rather than the person entering it.
     */
    public function receive(PurchaseOrder $po, array $data, ?int $actorId = null): GoodsReceiptNote
    {
        if (in_array($po->status, [PurchaseOrder::STATUS_CANCELLED, PurchaseOrder::STATUS_DECLINED], true)) {
            throw new RuntimeException('Nothing can be received against this order.');
        }

        $received = (float) $data['quantity_received'];
        if ($received <= 0) {
            throw new RuntimeException('A receipt has to record a quantity.');
        }

        return DB::transaction(function () use ($po, $data, $received, $actorId) {
            $ordered = (float) $po->quantity_ordered;
            $alreadyIn = $po->quantityReceived();
            $receivedAt = $data['received_at'] ?? now();

            $grn = $po->receipts()->create([
                'organization_id'   => $po->organization_id,
                'reference'         => ReferenceSequence::next('GRN', 'goods_receipt_notes', $po->organization_id),
                'quantity_ordered'  => $ordered,
                'quantity_received' => $received,
                // What is still outstanding after this delivery.
                'variance'          => round($ordered - ($alreadyIn + $received), 2),
                'quality_status'    => $data['quality_status'] ?? 'accepted',
                'quality_notes'     => $data['quality_notes'] ?? null,
                'on_time'           => $po->expected_delivery_date
                    ? ! \Illuminate\Support\Carbon::parse($receivedAt)->startOfDay()->greaterThan($po->expected_delivery_date)
                    : null,
                'received_by'       => $actorId,
                'received_at'       => $receivedAt,
            ]);

            $this->refreshStatus($po->fresh());
            $this->rematchOpenInvoices($po->fresh());

            return $grn;
        });
    }

    /**
     * An invoice that arrived before the goods failed its match on that alone.
     * Now the delivery is in, re-run it: otherwise a supplier who invoices on
     * dispatch leaves every bill sitting in exception, waiting on an override
     * for a problem that has since resolved itself.
     */
    private function rematchOpenInvoices(PurchaseOrder $po): void
    {
        $open = \App\Models\Procurement\SupplierInvoice::where('purchase_order_id', $po->id)
            ->whereIn('status', [
                \App\Models\Procurement\SupplierInvoice::STATUS_PENDING,
                \App\Models\Procurement\SupplierInvoice::STATUS_EXCEPTION,
            ])
            // An exception a person has already ruled on stands as their call.
            ->whereNull('exception_overridden_by')
            ->get();

        foreach ($open as $invoice) {
            app(InvoiceMatchService::class)->match($invoice);
        }
    }

    public function cancel(PurchaseOrder $po, ?string $reason = null): PurchaseOrder
    {
        if ($po->status === PurchaseOrder::STATUS_RECEIVED) {
            throw new RuntimeException('A received order cannot be cancelled.');
        }

        $po->update(['status' => PurchaseOrder::STATUS_CANCELLED, 'acceptance_note' => $reason]);
        $po->request?->update(['lifecycle_stage' => 'awarded', 'status' => 'requested']);

        return $po->fresh();
    }

    /** The live order for a request, if one is out there. */
    public function openOrderFor(ProcurementRequest $request): ?PurchaseOrder
    {
        return PurchaseOrder::where('procurement_request_id', $request->id)
            ->whereIn('status', array_merge(PurchaseOrder::OPEN_STATUSES, [PurchaseOrder::STATUS_RECEIVED]))
            ->first();
    }

    /** Status follows the arithmetic of what has arrived. */
    private function refreshStatus(PurchaseOrder $po): void
    {
        $ordered = (float) $po->quantity_ordered;
        $in = $po->quantityReceived();

        $status = $in >= $ordered && $ordered > 0
            ? PurchaseOrder::STATUS_RECEIVED
            : PurchaseOrder::STATUS_PARTIAL;

        $po->update(['status' => $status]);

        $po->request?->update([
            'lifecycle_stage' => $status === PurchaseOrder::STATUS_RECEIVED ? 'received' : 'delivering',
            'status'          => $status === PurchaseOrder::STATUS_RECEIVED ? 'received' : 'ordered',
        ]);

        $this->syncMaterialOrder($po->fresh());
    }

    /**
     * Attach the older supplier-order register to this PO where the purchase
     * came from a project order that already spawned one, so the Orders tab
     * shows the real PO reference instead of a blank LPO field.
     */
    private function bridgeToMaterialOrder(PurchaseOrder $po): void
    {
        $request = $po->request;
        if (! $request?->project_id) {
            return;
        }

        $mo = ProjectMaterialOrder::whereHas('project', fn ($q) => $q->whereKey($request->project_id))
            ->where('project_id', $request->project_id)
            ->whereNull('lpo_reference')
            ->where('name', $request->item)
            ->first();

        if (! $mo) {
            return;
        }

        $po->update(['material_order_id' => $mo->id]);
        $this->syncMaterialOrder($po->fresh());
    }

    /** One-way: the PO's state is mirrored onto the material order, never back. */
    private function syncMaterialOrder(PurchaseOrder $po): void
    {
        $mo = $po->materialOrder;
        if (! $mo) {
            return;
        }

        $status = match ($po->status) {
            PurchaseOrder::STATUS_RECEIVED  => ProjectMaterialOrder::STATUS_DELIVERED,
            PurchaseOrder::STATUS_PARTIAL   => ProjectMaterialOrder::STATUS_IN_TRANSIT,
            PurchaseOrder::STATUS_CANCELLED,
            PurchaseOrder::STATUS_DECLINED  => ProjectMaterialOrder::STATUS_CANCELLED,
            default                          => ProjectMaterialOrder::STATUS_ORDERED,
        };

        $mo->update(['status' => $status, 'lpo_reference' => $po->reference]);
    }

    /** The BOM-generated order line finally has a real PO behind its status. */
    private function markSourceOrderReleased(ProcurementRequest $request): void
    {
        ProjectOrder::where('fulfillable_type', ProcurementRequest::class)
            ->where('fulfillable_id', $request->id)
            ->update(['status' => ProjectOrder::STATUS_PO_RELEASED]);
    }
}
