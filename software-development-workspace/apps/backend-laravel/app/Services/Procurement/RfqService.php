<?php

namespace App\Services\Procurement;

use App\Models\Procurement\ProcurementQuotation;
use App\Models\Procurement\ProcurementRequest;
use App\Models\Procurement\Rfq;
use App\Support\Account\ReferenceSequence;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Competitive sourcing: taking an approved purchase out to quote, comparing
 * what comes back, and awarding it.
 *
 * Scoring is deliberately split. Price and lead time are scored from the
 * quotations themselves — the cheapest and the fastest set the bar, everyone
 * else is measured against them — because those comparisons are arithmetic and
 * arguing about them wastes time. Technical fit and vendor confidence are left
 * to the buyer, because they are judgements and pretending otherwise would
 * dress an opinion up as a calculation.
 */
class RfqService
{
    public function __construct(private ProcurementBudgetService $budget)
    {
    }

    /** Open a sourcing round for an approved purchase. */
    public function open(ProcurementRequest $request, ?int $actorId = null, ?string $dueDate = null): Rfq
    {
        if ($request->approval_status !== 'approved') {
            // Sourcing commits people's time and signals intent to suppliers.
            // Doing it before the purchase is approved invites the awkward
            // conversation where the answer turns out to be no.
            throw new RuntimeException('The purchase must be approved before it goes out to quote.');
        }

        if ($existing = $request->rfq) {
            return $existing;
        }

        $rfq = Rfq::create([
            'organization_id'        => $request->organization_id,
            'procurement_request_id' => $request->id,
            'reference'              => ReferenceSequence::next('RFQ', 'rfqs', $request->organization_id),
            'status'                 => 'issued',
            'issue_date'             => now()->toDateString(),
            'due_date'               => $dueDate,
            'created_by'             => $actorId,
        ]);

        $request->update(['lifecycle_stage' => 'rfq']);

        return $rfq;
    }

    /** Invite a supplier to quote. */
    public function invite(Rfq $rfq, int $vendorPartnerId): Rfq
    {
        $rfq->invitations()->firstOrCreate(
            ['vendor_partner_id' => $vendorPartnerId],
            ['organization_id' => $rfq->organization_id, 'invited_at' => now(), 'status' => 'invited'],
        );

        return $rfq->fresh(['invitations', 'quotations']);
    }

    /** Record what a supplier came back with, and re-score the round. */
    public function recordQuotation(Rfq $rfq, array $data, ?int $actorId = null): Rfq
    {
        if ($rfq->status === 'cancelled') {
            throw new RuntimeException('This sourcing round is cancelled.');
        }

        DB::transaction(function () use ($rfq, $data, $actorId) {
            $rfq->quotations()->updateOrCreate(
                ['vendor_partner_id' => $data['vendor_partner_id']],
                array_merge($data, ['organization_id' => $rfq->organization_id, 'created_by' => $actorId]),
            );

            // An invited supplier who quotes has responded; one who quotes
            // without being invited is still a quotation worth having.
            $rfq->invitations()->updateOrCreate(
                ['vendor_partner_id' => $data['vendor_partner_id']],
                ['organization_id' => $rfq->organization_id, 'status' => 'quoted', 'responded_at' => now()],
            );

            $this->score($rfq->fresh('quotations'));
            $this->refreshCompliance($rfq);
            $rfq->request?->update(['lifecycle_stage' => 'quoted']);
        });

        return $rfq->fresh(['invitations', 'quotations']);
    }

    /**
     * Score every quotation in the round.
     *
     * Price and delivery are relative: the best quote scores full marks and
     * the others are scaled against it, so a score means "how this compares
     * with what else was offered" rather than an absolute nobody can calibrate.
     */
    public function score(Rfq $rfq): void
    {
        $quotes = $rfq->quotations()->get();
        if ($quotes->isEmpty()) {
            return;
        }

        $bestPrice = (float) $quotes->min('amount');
        $leadTimes = $quotes->pluck('lead_time_days')->filter()->all();
        $bestLead = $leadTimes ? min($leadTimes) : null;

        foreach ($quotes as $quote) {
            $amount = (float) $quote->amount;
            $priceScore = $amount > 0 && $bestPrice > 0 ? round(($bestPrice / $amount) * 100, 2) : null;

            $deliveryScore = null;
            if ($bestLead !== null && $quote->lead_time_days) {
                $deliveryScore = round(($bestLead / max(1, (int) $quote->lead_time_days)) * 100, 2);
            }

            $w = ProcurementQuotation::WEIGHTS;
            // A criterion nobody scored is left out of the weighting rather
            // than counted as zero, which would punish an unscored quote.
            $parts = array_filter([
                'price'     => $priceScore !== null ? [$priceScore, $w['price']] : null,
                'delivery'  => $deliveryScore !== null ? [$deliveryScore, $w['delivery']] : null,
                'technical' => $quote->technical_score !== null ? [(float) $quote->technical_score, $w['technical']] : null,
                'vendor'    => $quote->vendor_score !== null ? [(float) $quote->vendor_score, $w['vendor']] : null,
            ]);

            $weight = array_sum(array_column($parts, 1));
            $total = $weight > 0
                ? round(array_sum(array_map(fn ($p) => $p[0] * $p[1], $parts)) / $weight, 2)
                : null;

            $quote->forceFill([
                'price_score'    => $priceScore,
                'delivery_score' => $deliveryScore,
                'total_score'    => $total,
            ])->save();
        }
    }

    /** Waive the three-quote rule, on the record. */
    public function waive(Rfq $rfq, string $reason, int $actorId): Rfq
    {
        $rfq->update([
            'waiver_reason'  => $reason,
            'waived_by'      => $actorId,
            'waived_at'      => now(),
            'compliance_met' => true,
        ]);

        return $rfq->fresh();
    }

    /**
     * Award the purchase to a supplier.
     *
     * Choosing anyone but the top score is allowed — price is not the only
     * thing that matters — but it has to be explained, because that is the
     * decision an auditor will ask about.
     */
    public function award(Rfq $rfq, int $quotationId, ?int $actorId = null, ?string $justification = null): ProcurementRequest
    {
        $request = $rfq->request;

        if (! $rfq->mayAward()) {
            throw new RuntimeException(sprintf(
                'A purchase needs %d quotations before it can be awarded — %d recorded. Waive the requirement with a reason to proceed.',
                Rfq::REQUIRED_QUOTES,
                $rfq->quotations()->count(),
            ));
        }

        $quote = $rfq->quotations()->findOrFail($quotationId);

        // Highest total score wins the recommendation. Sorted in PHP rather
        // than SQL because the relation carries its own ordering, and an
        // orderBy layered on top of it does not mean what it looks like.
        $best = $rfq->quotations()->get()
            ->filter(fn ($q) => $q->total_score !== null)
            ->sortByDesc(fn ($q) => (float) $q->total_score)
            ->first();

        if ($best && $best->id !== $quote->id && ! $justification) {
            throw new RuntimeException('This is not the highest-scoring quotation — record why it was chosen.');
        }

        return DB::transaction(function () use ($rfq, $quote, $request, $justification) {
            $rfq->quotations()->update(['is_awarded' => false]);
            $quote->update(['is_awarded' => true, 'award_justification' => $justification]);
            $rfq->update(['status' => 'closed']);

            $request->update([
                'awarded_vendor_id' => $quote->vendor_partner_id,
                'awarded_amount'    => $quote->amount,
                'vendor_partner_id' => $quote->vendor_partner_id,
                'lifecycle_stage'   => 'awarded',
            ]);

            // The commitment now rests on the awarded price rather than the
            // estimate, so the budget reflects what was actually agreed.
            $this->budget->check($request->fresh());

            return $request->fresh(['approvals']);
        });
    }

    /** Compliance is met by quote count; a waiver sets it explicitly. */
    private function refreshCompliance(Rfq $rfq): void
    {
        if ($rfq->waiver_reason) {
            return;
        }

        $rfq->update(['compliance_met' => $rfq->quotations()->count() >= Rfq::REQUIRED_QUOTES]);
    }
}
