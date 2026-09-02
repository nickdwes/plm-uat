<?php

namespace App\Services\Procurement;

use App\Models\Procurement\ProcurementRequest;
use App\Models\Procurement\ProcurementSetting;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Procurement\SupplierInvoice;
use App\Models\Project\ProjectExpense;
use App\Support\Account\ReferenceSequence;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The three-way match, payment, and the point where a promise becomes a cost.
 *
 * An invoice is checked against the order that authorised it and the goods that
 * actually arrived. All three legs have to agree — there is an order, the goods
 * are in, and the price is the one agreed within tolerance — or the invoice is
 * an exception somebody has to look at rather than something that quietly gets
 * paid.
 *
 * Paying posts a project expense, and that is deliberately the same moment the
 * purchase stops counting as committed: money is either promised or spent,
 * never both, or every project would look twice as expensive as it is.
 */
class InvoiceMatchService
{
    /** Record an invoice against a purchase order and match it immediately. */
    public function record(PurchaseOrder $po, array $data, ?int $actorId = null): SupplierInvoice
    {
        if (in_array($po->status, [PurchaseOrder::STATUS_CANCELLED, PurchaseOrder::STATUS_DECLINED], true)) {
            throw new RuntimeException('This order was never placed with the supplier.');
        }

        $amount = (float) $data['amount'];
        $tax = (float) ($data['tax_amount'] ?? 0);

        $invoice = DB::transaction(function () use ($po, $data, $amount, $tax, $actorId) {
            $invoice = SupplierInvoice::create([
                'organization_id'   => $po->organization_id,
                'purchase_order_id' => $po->id,
                'vendor_partner_id' => $po->vendor_partner_id,
                'reference'         => ReferenceSequence::next('SINV', 'supplier_invoices', $po->organization_id),
                'invoice_number'    => $data['invoice_number'] ?? null,
                'invoice_date'      => $data['invoice_date'] ?? now()->toDateString(),
                'due_date'          => $data['due_date'] ?? $this->dueDate($po, $data['invoice_date'] ?? null),
                'amount'            => $amount,
                'tax_amount'        => $tax,
                'total_amount'      => round($amount + $tax, 2),
                'currency'          => strtoupper($data['currency'] ?? $po->currency ?? 'KES'),
                'created_by'        => $actorId,
            ]);

            $po->request?->update(['lifecycle_stage' => 'invoiced']);

            return $invoice;
        });

        return $this->match($invoice->fresh());
    }

    /**
     * Run the three-way match and record each leg's answer.
     *
     * The price leg compares what is being billed with what the order says,
     * allowing the organisation's tolerance — suppliers round, freight moves,
     * and a rule with no slack in it turns every invoice into an exception.
     */
    public function match(SupplierInvoice $invoice): SupplierInvoice
    {
        $po = $invoice->purchaseOrder;
        $tolerance = (float) ProcurementSetting::forOrganization($invoice->organization_id)->price_match_tolerance_percent;

        $matchedPo = $po !== null && ! in_array($po->status, [PurchaseOrder::STATUS_CANCELLED, PurchaseOrder::STATUS_DECLINED], true);

        // Goods have to be in before the bill is paid, and enough of them: the
        // invoiced share of the order must be covered by what was received.
        $received = $po?->quantityReceived() ?? 0.0;
        $ordered = (float) ($po?->quantity_ordered ?? 0);
        $invoicedShare = $ordered > 0 && (float) $po->total_amount > 0
            ? $ordered * ((float) $invoice->total_amount / (float) $po->total_amount)
            : 0.0;
        $matchedGrn = $received > 0 && $received + 0.001 >= $invoicedShare;

        // Price: the total billed so far must not exceed the order beyond tolerance.
        $billedSoFar = (float) SupplierInvoice::where('purchase_order_id', $po?->id)
            ->whereKeyNot($invoice->id)
            ->whereNotIn('status', [SupplierInvoice::STATUS_CANCELLED])
            ->sum('total_amount') + (float) $invoice->total_amount;
        $ceiling = (float) ($po?->total_amount ?? 0) * (1 + $tolerance / 100);
        $matchedPrice = $po !== null && $billedSoFar <= $ceiling + 0.001;

        $reasons = array_filter([
            ! $matchedPo ? 'No live purchase order stands behind this invoice.' : null,
            ! $matchedGrn ? sprintf('Goods not received in full: %s of %s against an invoiced share of %s.',
                rtrim(rtrim(number_format($received, 2), '0'), '.'),
                rtrim(rtrim(number_format($ordered, 2), '0'), '.'),
                rtrim(rtrim(number_format($invoicedShare, 2), '0'), '.')) : null,
            ! $matchedPrice ? sprintf('Billed %s against an order of %s (tolerance %s%%).',
                number_format($billedSoFar, 2), number_format((float) ($po?->total_amount ?? 0), 2), rtrim(rtrim(number_format($tolerance, 2), '0'), '.')) : null,
        ]);

        $matched = $matchedPo && $matchedGrn && $matchedPrice;

        $invoice->update([
            'matched_po'       => $matchedPo,
            'matched_grn'      => $matchedGrn,
            'matched_price'    => $matchedPrice,
            'match_status'     => $matched ? 'matched' : 'exception',
            'exception_reason' => $matched ? null : implode(' ', $reasons),
            // A matched invoice is ready for payment; an unmatched one waits
            // for a person, and neither state is reached by accident.
            'status'           => $invoice->status === SupplierInvoice::STATUS_PAID
                ? SupplierInvoice::STATUS_PAID
                : ($matched ? SupplierInvoice::STATUS_APPROVED : SupplierInvoice::STATUS_EXCEPTION),
        ]);

        return $invoice->fresh();
    }

    /** Let an exception through, on the record, with a reason. */
    public function overrideException(SupplierInvoice $invoice, string $justification, int $actorId): SupplierInvoice
    {
        if ($invoice->match_status !== 'exception') {
            throw new RuntimeException('This invoice has nothing to override.');
        }

        $invoice->update([
            'status'                  => SupplierInvoice::STATUS_APPROVED,
            'exception_overridden_by' => $actorId,
            'override_justification'  => $justification,
        ]);

        return $invoice->fresh();
    }

    /**
     * Settle the invoice, and post the spend.
     *
     * The project expense is keyed on the invoice reference so paying twice —
     * a retry, a double click, a re-run — cannot charge the project twice.
     */
    public function pay(SupplierInvoice $invoice, array $data, ?int $actorId = null): SupplierInvoice
    {
        if ($invoice->status === SupplierInvoice::STATUS_PAID) {
            return $invoice;
        }

        if ($invoice->status !== SupplierInvoice::STATUS_APPROVED) {
            throw new RuntimeException('Only an invoice approved for payment can be paid.');
        }

        return DB::transaction(function () use ($invoice, $data, $actorId) {
            $amount = (float) ($data['paid_amount'] ?? $invoice->total_amount);

            $invoice->update([
                'paid_amount'       => $amount,
                'paid_at'           => $data['paid_at'] ?? now(),
                'payment_reference' => $data['payment_reference'] ?? null,
                'status'            => SupplierInvoice::STATUS_PAID,
            ]);

            $this->postExpense($invoice->fresh(), $actorId);
            $this->settleRequest($invoice->purchaseOrder?->request);

            return $invoice->fresh();
        });
    }

    /**
     * Close the purchase: the goods are in, the bills are paid, nothing more
     * will happen to it. A closed purchase is settled history and is locked.
     */
    public function close(ProcurementRequest $request, ?int $actorId = null): ProcurementRequest
    {
        if ($request->isClosed()) {
            return $request;
        }

        $po = PurchaseOrder::where('procurement_request_id', $request->id)
            ->whereNotIn('status', [PurchaseOrder::STATUS_CANCELLED, PurchaseOrder::STATUS_DECLINED])
            ->latest('id')->first();

        if (! $po || $po->status !== PurchaseOrder::STATUS_RECEIVED) {
            throw new RuntimeException('The goods have not all been received yet.');
        }

        $unpaid = SupplierInvoice::where('purchase_order_id', $po->id)
            ->whereIn('status', SupplierInvoice::OPEN_STATUSES)->count();
        if ($unpaid > 0) {
            throw new RuntimeException("{$unpaid} invoice(s) on this purchase are still unpaid.");
        }

        if (SupplierInvoice::where('purchase_order_id', $po->id)->where('status', SupplierInvoice::STATUS_PAID)->doesntExist()) {
            throw new RuntimeException('No invoice has been paid against this purchase.');
        }

        $request->update([
            'lifecycle_stage' => 'closed',
            'closed_at'       => now(),
            'closed_by'       => $actorId,
        ]);

        $po->update(['status' => PurchaseOrder::STATUS_CLOSED]);

        return $request->fresh();
    }

    /** The moment a promise becomes a cost. */
    private function postExpense(SupplierInvoice $invoice, ?int $actorId): void
    {
        $request = $invoice->purchaseOrder?->request;
        $project = $request?->project;

        if (! $project) {
            return;   // a purchase with no project has no expense to post
        }

        ProjectExpense::updateOrCreate(
            ['project_id' => $project->id, 'note' => $invoice->reference],
            [
                'organization_id' => $project->organization_id,
                'label'           => $request->item,
                'category'        => 'procurement',
                'currency'        => strtoupper($invoice->currency),
                'amount'          => $invoice->paid_amount,
                'spent_on'        => $invoice->paid_at?->toDateString() ?? now()->toDateString(),
                'created_by'      => $actorId,
            ],
        );
    }

    /** A fully settled purchase reads as paid. */
    private function settleRequest(?ProcurementRequest $request): void
    {
        if (! $request) {
            return;
        }

        $po = PurchaseOrder::where('procurement_request_id', $request->id)
            ->whereNotIn('status', [PurchaseOrder::STATUS_CANCELLED, PurchaseOrder::STATUS_DECLINED])
            ->latest('id')->first();

        $outstanding = $po
            ? SupplierInvoice::where('purchase_order_id', $po->id)->whereIn('status', SupplierInvoice::OPEN_STATUSES)->count()
            : 0;

        if ($outstanding === 0) {
            $request->update(['lifecycle_stage' => 'paid']);
        }
    }

    /** The supplier's terms decide when it falls due, when nobody says. */
    private function dueDate(PurchaseOrder $po, ?string $invoiceDate): ?string
    {
        $days = $po->vendor?->payment_terms_days;

        return $days
            ? \Illuminate\Support\Carbon::parse($invoiceDate ?? now())->addDays((int) $days)->toDateString()
            : null;
    }
}
