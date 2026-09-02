<?php

namespace App\Services\Procurement;

use App\Models\Procurement\GoodsReceiptNote;
use App\Models\Procurement\ProcurementQuotation;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Procurement\SupplierInvoice;
use App\Models\Training\VendorPartner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What a supplier is actually like to buy from.
 *
 * Every number here is derived from what already happened — the orders placed,
 * the deliveries received, the quotes given — and none of it is stored. A
 * scorecard column on the vendor would start drifting the moment a GRN is
 * corrected, and there is no volume here that makes a cached aggregate worth
 * that risk. The trade is a handful of queries per vendor, paid only when
 * somebody actually looks.
 *
 * A metric with nothing behind it returns null rather than zero: a vendor
 * nobody has ordered from is not a vendor with 0% on-time delivery, and
 * showing them as one would bury a new supplier before their first order.
 */
class VendorPerformanceService
{
    /** How the four measures combine into one number out of 100. */
    public const WEIGHTS = [
        'on_time' => 35,
        'quality' => 30,
        'price'   => 20,
        'response' => 15,
    ];

    /**
     * The full scorecard for one supplier.
     *
     * @return array<string, mixed>
     */
    public function scorecard(VendorPartner $vendor, ?string $since = null): array
    {
        $orders = $this->orders($vendor, $since);
        $grns = $this->receipts($orders);

        $onTime = $this->onTimeRate($grns);
        $quality = $this->qualityRate($grns);
        $price = $this->priceCompetitiveness($vendor, $since);
        $response = $this->responsiveness($orders);

        $scores = [
            'on_time'  => $onTime,
            'quality'  => $quality,
            'price'    => $price,
            'response' => $response,
        ];

        // An overall rating is a claim about how a supplier performs, and a
        // quote is not performance. Without a single delivery to their name the
        // only measure with a value is price competitiveness, which would then
        // carry the whole score — and a supplier who has never sent anything
        // would rank "excellent" above one with a real delivery record.
        $overall = $grns->isEmpty() ? null : $this->overall($scores);

        return [
            'vendor' => ['id' => $vendor->id, 'name' => $vendor->name],
            'since'  => $since,
            'volume' => [
                'orders'        => $orders->count(),
                'deliveries'    => $grns->count(),
                'order_value'   => $this->orderValue($orders),
                'spend'         => $this->spend($orders),
                'open_orders'   => $orders->whereIn('status', PurchaseOrder::OPEN_STATUSES)->count(),
                'currency'      => $orders->first()?->currency ?? 'KES',
            ],
            'delivery' => [
                'on_time_rate'      => $onTime,
                'on_time_count'     => $grns->where('on_time', true)->count(),
                'late_count'        => $grns->where('on_time', false)->count(),
                'avg_lead_days'     => $this->averageLeadDays($orders),
                'quoted_lead_days'  => $this->quotedLeadDays($vendor, $since),
            ],
            'quality' => [
                'acceptance_rate' => $quality,
                'accepted'        => $grns->where('quality_status', 'accepted')->count(),
                'partial'         => $grns->where('quality_status', 'partial')->count(),
                'rejected'        => $grns->where('quality_status', 'rejected')->count(),
            ],
            'commercial' => [
                'price_competitiveness' => $price,
                'quotes_given'          => $this->quotes($vendor, $since)->count(),
                'quotes_won'            => $this->quotes($vendor, $since)->where('is_awarded', true)->count(),
                'win_rate'              => $this->winRate($vendor, $since),
                'invoice_exceptions'    => $this->invoiceExceptions($orders),
            ],
            'responsiveness' => [
                'acceptance_rate'    => $response,
                'declined_orders'    => $orders->where('status', PurchaseOrder::STATUS_DECLINED)->count(),
                'avg_accept_hours'   => $this->averageAcceptHours($orders),
            ],
            'overall' => $overall,
            'rating'  => $this->rating($overall),
        ];
    }

    /**
     * Every supplier ranked, best first. Suppliers with no completed business
     * sort last rather than being dropped — "we have never bought from them"
     * is itself worth seeing on a ranking.
     */
    public function ranking(int $organizationId, ?string $since = null, int $limit = 50): array
    {
        return VendorPartner::where('organization_id', $organizationId)
            ->orderBy('name')->get()
            ->map(fn (VendorPartner $v) => $this->summary($v, $since))
            ->sortByDesc(fn (array $r) => $r['overall'] ?? -1)
            ->values()->take($limit)->all();
    }

    /** The scorecard trimmed to what a ranking row needs. */
    public function summary(VendorPartner $vendor, ?string $since = null): array
    {
        $card = $this->scorecard($vendor, $since);

        return [
            'vendor_id'    => $vendor->id,
            'vendor'       => $vendor->name,
            'orders'       => $card['volume']['orders'],
            'spend'        => $card['volume']['spend'],
            'currency'     => $card['volume']['currency'],
            'on_time_rate' => $card['delivery']['on_time_rate'],
            'quality_rate' => $card['quality']['acceptance_rate'],
            'avg_lead_days' => $card['delivery']['avg_lead_days'],
            'win_rate'     => $card['commercial']['win_rate'],
            'overall'      => $card['overall'],
            'rating'       => $card['rating'],
        ];
    }

    // ── the measures ─────────────────────────────────────────────────────────

    /** Deliveries that arrived by the date the order promised. */
    private function onTimeRate(Collection $grns): ?float
    {
        // A GRN against an order with no expected date has on_time null — it
        // cannot be late against a deadline nobody set, so it is not evidence
        // either way and is left out of the denominator.
        $judged = $grns->whereNotNull('on_time');

        return $judged->isEmpty() ? null : round($judged->where('on_time', true)->count() / $judged->count() * 100, 1);
    }

    /** Deliveries accepted outright, with a partial counting as half. */
    private function qualityRate(Collection $grns): ?float
    {
        if ($grns->isEmpty()) {
            return null;
        }

        $score = $grns->sum(fn (GoodsReceiptNote $g) => match ($g->quality_status) {
            'accepted' => 1.0,
            'partial'  => 0.5,
            default    => 0.0,
        });

        return round($score / $grns->count() * 100, 1);
    }

    /**
     * How this supplier's quotes compare with everyone else's on the same
     * sourcing rounds. 100 means they were always the cheapest bid on the
     * table; below 50 means they are habitually the expensive option.
     */
    private function priceCompetitiveness(VendorPartner $vendor, ?string $since): ?float
    {
        $quotes = $this->quotes($vendor, $since);

        if ($quotes->isEmpty()) {
            return null;
        }

        $ratios = [];

        foreach ($quotes as $quote) {
            // Only a round with a rival bid says anything about price.
            $rivals = ProcurementQuotation::where('rfq_id', $quote->rfq_id)
                ->whereKeyNot($quote->id)->pluck('amount')->map(fn ($a) => (float) $a)->filter();

            if ($rivals->isEmpty()) {
                continue;
            }

            $best = min($rivals->min(), (float) $quote->amount);
            $mine = (float) $quote->amount;

            if ($mine <= 0 || $best <= 0) {
                continue;
            }

            // Cheapest on the round scores 100; twice the best price scores 50.
            $ratios[] = min(100, $best / $mine * 100);
        }

        return $ratios === [] ? null : round(array_sum($ratios) / count($ratios), 1);
    }

    /** Orders the supplier accepted rather than declining or ignoring. */
    private function responsiveness(Collection $orders): ?float
    {
        $answered = $orders->whereIn('status', [
            PurchaseOrder::STATUS_ACCEPTED, PurchaseOrder::STATUS_DECLINED,
            PurchaseOrder::STATUS_PARTIAL, PurchaseOrder::STATUS_RECEIVED, PurchaseOrder::STATUS_CLOSED,
        ]);

        if ($answered->isEmpty()) {
            return null;
        }

        $declined = $answered->where('status', PurchaseOrder::STATUS_DECLINED)->count();

        return round(($answered->count() - $declined) / $answered->count() * 100, 1);
    }

    /** Days from placing the order to the last delivery against it. */
    private function averageLeadDays(Collection $orders): ?float
    {
        $spans = [];

        foreach ($orders as $order) {
            $last = $order->receipts->sortByDesc('received_at')->first();

            if (! $order->issued_at || ! $last?->received_at) {
                continue;
            }

            $spans[] = $order->issued_at->diffInDays($last->received_at);
        }

        return $spans === [] ? null : round(array_sum($spans) / count($spans), 1);
    }

    /** What they said the lead time would be, for comparison with reality. */
    private function quotedLeadDays(VendorPartner $vendor, ?string $since): ?float
    {
        $days = $this->quotes($vendor, $since)->pluck('lead_time_days')->filter()->map(fn ($d) => (float) $d);

        return $days->isEmpty() ? null : round($days->avg(), 1);
    }

    private function averageAcceptHours(Collection $orders): ?float
    {
        $spans = $orders->filter(fn (PurchaseOrder $o) => $o->issued_at && $o->accepted_at)
            ->map(fn (PurchaseOrder $o) => $o->issued_at->diffInHours($o->accepted_at));

        return $spans->isEmpty() ? null : round($spans->avg(), 1);
    }

    private function winRate(VendorPartner $vendor, ?string $since): ?float
    {
        $quotes = $this->quotes($vendor, $since);

        return $quotes->isEmpty() ? null : round($quotes->where('is_awarded', true)->count() / $quotes->count() * 100, 1);
    }

    private function invoiceExceptions(Collection $orders): int
    {
        return $orders->isEmpty() ? 0 : SupplierInvoice::whereIn('purchase_order_id', $orders->pluck('id'))
            ->where('match_status', 'exception')->count();
    }

    /**
     * The four measures as one number, weighting only what there is evidence
     * for — a supplier with no quote history is not penalised for it, their
     * remaining measures simply carry more of the total.
     */
    private function overall(array $scores): ?float
    {
        $weighted = 0.0;
        $weight = 0;

        foreach ($scores as $key => $value) {
            if ($value === null) {
                continue;
            }

            $weighted += $value * self::WEIGHTS[$key];
            $weight += self::WEIGHTS[$key];
        }

        return $weight === 0 ? null : round($weighted / $weight, 1);
    }

    private function rating(?float $overall): ?string
    {
        return match (true) {
            $overall === null => null,
            $overall >= 90    => 'excellent',
            $overall >= 75    => 'good',
            $overall >= 60    => 'fair',
            default           => 'poor',
        };
    }

    // ── the raw material ─────────────────────────────────────────────────────

    /** @return Collection<int, PurchaseOrder> */
    private function orders(VendorPartner $vendor, ?string $since): Collection
    {
        return PurchaseOrder::where('vendor_partner_id', $vendor->id)
            ->when($since, fn ($q) => $q->where('issued_at', '>=', Carbon::parse($since)))
            ->with('receipts')->get();
    }

    /** @return Collection<int, GoodsReceiptNote> */
    private function receipts(Collection $orders): Collection
    {
        return $orders->flatMap(fn (PurchaseOrder $o) => $o->receipts);
    }

    /** @return Collection<int, ProcurementQuotation> */
    private function quotes(VendorPartner $vendor, ?string $since): Collection
    {
        return ProcurementQuotation::where('vendor_partner_id', $vendor->id)
            ->when($since, fn ($q) => $q->where('created_at', '>=', Carbon::parse($since)))
            ->get();
    }

    private function orderValue(Collection $orders): float
    {
        return round((float) $orders->whereNotIn('status', [
            PurchaseOrder::STATUS_CANCELLED, PurchaseOrder::STATUS_DECLINED,
        ])->sum('total_amount'), 2);
    }

    /** What has actually been paid to them. */
    private function spend(Collection $orders): float
    {
        return $orders->isEmpty() ? 0.0 : round((float) SupplierInvoice::whereIn('purchase_order_id', $orders->pluck('id'))
            ->where('status', SupplierInvoice::STATUS_PAID)->sum('paid_amount'), 2);
    }
}
