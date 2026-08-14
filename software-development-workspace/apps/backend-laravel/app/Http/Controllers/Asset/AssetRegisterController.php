<?php

namespace App\Http\Controllers\Asset;

use App\Http\Controllers\Controller;
use App\Models\Asset\Asset;
use App\Models\Asset\AssetDisposal;
use App\Models\Asset\AssetIssue;
use App\Models\Asset\AssetMaintenance;
use App\Models\Asset\AssetRequest;
use App\Services\Asset\AssetRequestChain;
use App\Services\Asset\RenewalSchedule;
use Carbon\CarbonImmutable;
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
            ->with(['requester:id,first_name,last_name', 'asset:id,name,asset_tag'])
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
                'employee'     => $r->requester?->full_name,
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
     * The software estate, counted.
     *
     * Utilisation is the number this exists for: seats paid for against seats
     * actually handed to somebody. It only became answerable when subscriptions
     * were separated from products — a product has no seats, its agreements do,
     * and totalling them requires knowing which belong together.
     *
     * Two figures from the brief are deliberately absent rather than guessed.
     * "Unlicensed software" needs discovery data from Intune or SCCM to compare
     * what is installed against what is entitled; without that integration the
     * number cannot be computed, only claimed. And a risk score would be a
     * weighting somebody invented, presented as arithmetic.
     */
    public function softwareDashboard(): JsonResponse
    {
        $this->authorize('viewAny', Asset::class);

        $products = Asset::query()
            ->where('asset_class', Asset::CLASS_SOFTWARE)
            ->where('status', '!=', Asset::STATUS_RETIRED)
            ->with([
                'children' => fn ($q) => $q->withCount(['seats as seats_used' => fn ($s) => $s->whereNull('released_on')]),
                // Which directory this came from. With one tenant it is the
                // same on every row; with two it is the difference between a
                // duplicate and a second company's licence.
                'integrationConnection:id,name',
            ])
            ->get();

        $agreements = Asset::query()
            ->where('asset_class', Asset::CLASS_SUBSCRIPTION)
            ->where('status', '!=', Asset::STATUS_RETIRED)
            ->withCount(['seats as seats_used' => fn ($s) => $s->whereNull('released_on')])
            ->get();

        $seatsBought = (int) $agreements->sum('seats_total');
        $seatsUsed   = (int) $agreements->sum('seats_used');

        /*
         * What the vendor says is in use, summed across the products.
         *
         * Kept separate from `$seatsUsed` for the reason the column above is:
         * one counts seats we can name a person for, the other is Microsoft's.
         * Until the accounts are matched only the second is populated.
         */
        $seatsReported = (int) $products->sum(fn ($p) => (int) $p->seats_assigned_reported);

        return response()->json([
            'totals' => [
                'products'   => $products->count(),
                'agreements' => $agreements->count(),
                // Software nobody is paying for through a tracked agreement.
                // Not the same as unlicensed — it may be perpetual, or free.
                'without_agreement' => $products->filter(fn ($p) => $p->children->isEmpty())->count(),
                'unsupported' => $products->filter(fn ($p) => $p->isUnsupported())->count(),
            ],

            'seats' => [
                'purchased'   => $seatsBought,
                'assigned'    => $seatsUsed,
                // Seats paid for and not handed out. The number that hands
                // money back at a renewal.
                'assigned_reported' => $seatsReported,
                'spare'       => max(0, $seatsBought - max($seatsUsed, $seatsReported)),
                'utilisation' => $seatsBought > 0 ? (int) round(100 * $seatsUsed / $seatsBought) : null,
                // What the numbers actually mean today, before matching exists.
                'utilisation_reported' => $seatsBought > 0
                    ? (int) round(100 * $seatsReported / $seatsBought)
                    : null,
            ],

            'renewals' => [
                'expiring_90_days' => $agreements->filter(
                    fn ($a) => $a->expires_on && $a->daysToExpiry() !== null
                        && $a->daysToExpiry() >= 0 && $a->daysToExpiry() <= 90,
                )->count(),
                'expired' => $agreements->filter(
                    fn ($a) => $a->daysToExpiry() !== null && $a->daysToExpiry() < 0,
                )->count(),
                // A guess, and labelled one everywhere it is shown.
                'forecast_12_months' => (float) $agreements->sum(
                    fn ($a) => (float) ($a->estimated_renewal_cost ?? $a->renewal_cost ?? 0),
                ),
            ],

            /*
             * Where the money and the risk sit. Products with no agreements
             * still appear, at nought — a product nobody is paying for is
             * itself worth seeing.
             */
            'by_product' => $products->map(function ($p) {
                $seats     = (int) $p->children->sum('seats_total');
                $allocated = (int) $p->children->sum('seats_used');

                /*
                 * Two different counts, deliberately both present.
                 *
                 * `allocated` is seats handed to somebody the register can name.
                 * `reported` is the vendor's own figure. Until employees are
                 * migrated the first is nought everywhere and the second is the
                 * only true one, so the screen leads with the vendor's and says
                 * whose it is — showing "0 of 486 in use" on a fully consumed
                 * tenant would be a wrong number presented as a finding.
                 */
                $reported = $p->seats_assigned_reported;
                $inUse    = $reported ?? $allocated;

                return [
                    'id'         => $p->id,
                    'name'       => $p->name,
                    'category'   => $p->category,
                    'tenant'     => $p->integrationConnection?->name,
                    'agreements' => $p->children->count(),
                    'seats'      => $seats,
                    'allocated'  => $allocated,
                    'reported'   => $reported,
                    'in_use'     => $inUse,
                    'available'  => max(0, $seats - $inUse),
                    /*
                     * The soonest of its agreements. A product bought twice
                     * renews twice, and the date that matters is the next one.
                     */
                    'next_renewal' => $p->children
                        ->pluck('expires_on')->filter()->sort()->first()?->toDateString(),
                    'annual'     => (float) $p->children->sum(
                        fn ($c) => (float) ($c->estimated_renewal_cost ?? $c->renewal_cost ?? 0),
                    ),
                    'criticality'  => $p->custom_fields['business_criticality'] ?? null,
                    'unsupported'  => $p->isUnsupported(),
                ];
            })
                // Soonest renewal first: it is the column somebody is scanning
                // for, and anything with no date sorts to the end rather than
                // to the top, where a null would otherwise land.
                ->sortBy(fn ($p) => $p['next_renewal'] ?? '9999-12-31')
                ->values(),

            'by_vendor' => $products->groupBy(fn ($p) => $p->vendor ?: 'Unrecorded')
                ->map(fn ($g, $vendor) => ['vendor' => $vendor, 'count' => $g->count()])
                ->sortByDesc('count')->values(),

            'by_criticality' => $products->groupBy(fn ($p) => $p->custom_fields['business_criticality'] ?? 'unrecorded')
                ->map(fn ($g, $level) => ['level' => $level, 'count' => $g->count()])
                ->values(),
        ]);
    }

    /**
     * What is coming up for renewal, and what it cost last time.
     *
     * The page a licence manager opens. Everything with a date, soonest first,
     * with the previous term's cost and vendor beside it — because the question
     * asked at a renewal is never just "when", it is "what did we pay, who did
     * we pay, and is it going up".
     *
     * Expired items lead. Something that lapsed last week is a worse problem
     * than something lapsing next month, and a list sorted purely by date
     * buries it above the fold.
     */
    public function renewals(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Asset::class);

        $withinDays = $request->integer('within') ?: null;

        $q = Asset::query()
            ->whereNotNull('expires_on')
            ->where('status', '!=', Asset::STATUS_RETIRED)
            ->with(['renewals' => fn ($r) => $r->latest('renewed_on')->limit(1), 'watchers.employee'])
            ->withCount(['seats as seats_used' => fn ($s) => $s->whereNull('released_on')])
            ->orderBy('expires_on');

        // The default view is what needs attention; the full list is opt-in.
        if (! $request->boolean('all')) {
            $q->expiringSoon($withinDays);
        }

        $schedule = new RenewalSchedule();
        $today    = CarbonImmutable::parse(now()->toDateString());

        $rows = $q->get()->map(function (Asset $a) use ($schedule, $today) {
            $last = $a->renewals->first();

            return [
                'id'          => $a->id,
                'name'        => $a->name,
                'asset_class' => $a->asset_class,
                'category'    => $a->category,
                'description' => $a->description,

                'expires_on'     => $a->expires_on?->toDateString(),
                'days_to_expiry' => $a->daysToExpiry(),
                'auto_renews'    => (bool) $a->auto_renews,

                // What it cost last time, and who from — the two facts a
                // renewal conversation opens with.
                'last_cost'     => $last?->cost,
                'last_vendor'   => $last?->vendor_name ?? $a->vendor,
                'last_renewed'  => $last?->renewed_on?->toDateString(),
                // What it is expected to cost, which is a guess and labelled one.
                'estimated_cost' => $a->estimated_renewal_cost ?? $a->renewal_cost,

                'seats_total' => $a->seats_total,
                'seats_used'  => $a->seats_used,

                'reminders_on' => (bool) $a->renewal_reminders_on,
                'watchers'     => $a->watchers->map(fn ($w) => [
                    'id' => $w->id, 'employee_id' => $w->employee_id, 'name' => $w->employee?->full_name,
                ])->values(),

                // What the person has signed up to receive, so the toggle is
                // not a promise nobody can see the shape of.
                'schedule' => $a->expires_on
                    ? $schedule->upcoming(CarbonImmutable::parse($a->expires_on->toDateString()), $today)
                    : [],
            ];
        });

        return response()->json([
            'data' => $rows->sortBy(fn ($r) => $r['days_to_expiry'])->values(),
            'summary' => [
                'expired'     => $rows->filter(fn ($r) => $r['days_to_expiry'] < 0)->count(),
                'this_month'  => $rows->filter(fn ($r) => $r['days_to_expiry'] >= 0 && $r['days_to_expiry'] <= 30)->count(),
                'total'       => $rows->count(),
                'estimated'   => (float) $rows->sum(fn ($r) => (float) ($r['estimated_cost'] ?? 0)),
            ],
        ]);
    }

    // ── Acting on a request ───────────────────────────────────────────────

    /** Move it to the next stage. */
    public function advanceRequest(Request $request, AssetRequest $assetRequest): JsonResponse
    {
        $this->authorize('approve', Asset::class);

        return response()->json([
            'data' => app(AssetRequestChain::class)
                ->advance($assetRequest, $request->user(), $request->input('note')),
        ]);
    }

    public function declineRequest(Request $request, AssetRequest $assetRequest): JsonResponse
    {
        $this->authorize('approve', Asset::class);

        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return response()->json([
            'data' => app(AssetRequestChain::class)
                ->decline($assetRequest, $request->user(), $data['reason']),
        ]);
    }

    public function forwardRequest(Request $request, AssetRequest $assetRequest): JsonResponse
    {
        $this->authorize('approve', Asset::class);

        return response()->json([
            'data' => app(AssetRequestChain::class)
                ->forwardToProcurement($assetRequest, $request->user(), $request->input('note')),
        ]);
    }

    /** Give them a specific asset off the shelf. */
    public function fulfilRequest(Request $request, AssetRequest $assetRequest): JsonResponse
    {
        $data = $request->validate([
            'asset_id'    => ['required', 'integer', 'exists:assets,id'],
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'note'        => ['nullable', 'string', 'max:500'],
        ]);

        $asset = Asset::findOrFail($data['asset_id']);

        // Against the asset itself, not the class — `assign` asks whether this
        // person may move *this* equipment, and it cannot answer without one.
        $this->authorize('assign', $asset);

        return response()->json([
            'data' => app(AssetRequestChain::class)->fulfil(
                $assetRequest, $asset, $request->user(),
                (int) $data['employee_id'], $data['note'] ?? null,
            ),
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
