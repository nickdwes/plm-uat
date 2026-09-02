<?php

namespace App\Http\Controllers\Asset;

use App\Http\Controllers\Controller;
use App\Models\Asset\Asset;
use App\Models\Asset\AssetDisposal;
use App\Models\Asset\AssetIssue;
use App\Models\Asset\AssetMaintenance;
use App\Models\Asset\AssetRequest;
use App\Services\Asset\AssetRequestChain;
use App\Support\Authorization\Permission;
use App\Services\Asset\RenewalSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
    /**
     * Bring the Microsoft 365 licences into the register, and put a name to
     * every seat.
     *
     * The same three steps that already existed on the integrations screen —
     * read the tenant, create the licence products, match the holders — run
     * here as one action, because this is the screen somebody is looking at
     * when they ask why the software register is empty. The connection is
     * resolved from the organisation rather than named by the caller: an
     * asset manager should not have to know a connection id.
     */
    public function syncLicences(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission(Permission::ASSETS_UPDATE), 403);

        $orgId = app('current.organization_id');

        $connection = \App\Models\Integration\IntegrationConnection::query()
            ->where('organization_id', $orgId)
            ->where('is_active', true)
            ->where('sync_licences', true)
            ->first();

        if (! $connection) {
            return response()->json([
                'message' => 'No active Microsoft 365 connection is set to read licences. '
                    . 'Set one up under Integrations first.',
            ], 422);
        }

        // Dry by default: the first run should say what it would do before it
        // writes rows into a register somebody reports from.
        $commit = ! $request->boolean('preview');

        try {
            $licences = $commit
                ? app(\App\Services\Integration\LicenceAssetImport::class)->import($connection)
                : app(\App\Services\Integration\LicenceAssetImport::class)->preview($connection);

            $owners = app(\App\Services\Integration\LicenceOwnerMapper::class)->run($connection, $commit);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($commit) {
            \App\Services\Audit\AuditLogger::record(
                action: 'assets.licences_synced',
                description: sprintf(
                    'Synced Microsoft 365 licences: %d seat(s) allocated, %d released, %d seat(s) held by people not on the register',
                    $owners['seats']['allocated'], $owners['seats']['released'], $owners['seats']['unmatched_seats'],
                ),
                organizationId: $orgId,
            );
        }

        return response()->json([
            'preview' => ! $commit,
            'result'  => ['licences' => $licences, 'owners' => $owners],
        ]);
    }

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

    /**
     * Ask for something you do not have.
     *
     * The chain could advance, decline, forward and fulfil a request since the
     * approval work — and nothing could raise one. Every stage after the first
     * existed for a record that had no way of being created, so the tab read
     * "No requests have been raised" and always would.
     */
    public function storeRequest(Request $request): JsonResponse
    {
        $this->authorize('create', Asset::class);

        $data = $request->validate([
            'item'        => ['required', 'string', 'max:160'],
            /*
             * Required, because the column is and because a request filed
             * under nothing cannot be matched against inventory — "is there a
             * laptop free" is the first question the chain asks.
             */
            'category'    => ['required', 'string', 'max:80'],
            'quantity'    => ['required', 'integer', 'min:1', 'max:500'],
            'urgency'     => ['required', 'in:low,normal,high'],
            'reason'      => ['required', 'string', 'max:1000'],
            /*
             * Who it is for, which is not always who is typing. HR raising a
             * request on somebody's behalf is ordinary; defaulting to the
             * caller keeps the common case one field shorter.
             */
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
        ]);

        $employeeId = $data['employee_id'] ?? $request->user()?->employeeId();

        abort_if(
            $employeeId === null,
            422,
            'Say who this is for — your account is not linked to an employee record.',
        );

        $employee = Employee::findOrFail($employeeId);

        // The same tenancy check assignment carries: `exists` knows nothing
        // about which organisation an employee belongs to.
        abort_unless(
            (int) $employee->primary_organization_id === (int) app('current.organization_id'),
            422,
            'That employee is not in the current organization.',
        );

        $organizationId = (int) app('current.organization_id');

        $created = DB::transaction(function () use ($data, $employeeId, $organizationId) {
            /*
             * Numbered per organisation, inside the transaction and behind a
             * lock. Two people asking at the same moment would otherwise be
             * handed the same reference and one of them would lose to the
             * unique index — with a 500 rather than anything explaining it.
             */
            $year = now()->format('Y');

            $last = AssetRequest::withoutGlobalScope('organization')
                ->where('organization_id', $organizationId)
                ->where('request_number', 'like', "AR-{$year}-%")
                ->lockForUpdate()
                ->orderByDesc('request_number')
                ->value('request_number');

            $next = $last ? ((int) substr($last, -4)) + 1 : 1;

            return AssetRequest::create($data + [
                'organization_id' => $organizationId,
                'employee_id'     => $employeeId,
                'request_number'  => sprintf('AR-%s-%04d', $year, $next),
                'status'          => AssetRequest::STATUS_PENDING,
            ]);
        });

        return response()->json([
            'data' => [
                'id'        => $created->id,
                'reference' => $created->request_number,
                'status'    => $created->status,
            ],
            'message' => "Raised as {$created->request_number}. It goes to HR first.",
        ], 201);
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
