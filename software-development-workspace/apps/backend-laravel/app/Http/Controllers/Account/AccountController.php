<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\StoreAccountRequest;
use App\Http\Requests\Account\StoreContactRequest;
use App\Http\Requests\Account\StoreContractRequest;
use App\Http\Resources\Account\AccountActivityResource;
use App\Http\Resources\Account\AccountAttachmentResource;
use App\Http\Resources\Account\AccountContactResource;
use App\Http\Resources\Account\AccountContractResource;
use App\Http\Resources\Account\AccountResource;
use App\Models\Account\Account;
use App\Models\Pipeline\Opportunity;
use App\Models\Account\AccountActivity;
use App\Models\Account\AccountAttachment;
use App\Models\Account\AccountContact;
use App\Models\Account\AccountContract;
use App\Models\Account\AccountSector;
use App\Models\Account\AccountSubsector;
use App\Models\Account\AccountTeamMember;
use App\Models\Account\AccountType;
use App\Services\Account\AccountActivityService;
use App\Services\Account\AccountService;
use App\Support\Account\ReferenceSequence;
use App\Support\Authorization\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use App\Support\Storage\DocumentVault;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The customer master.
 *
 * Gated on the permission catalogue from the first commit rather than on role
 * names, so this does not join the controllers that have to be migrated later.
 */
class AccountController extends Controller
{
    /**
     * Everything the record screen shows. Named once so a relation added to
     * the resource cannot be loaded by three endpoints and missed by the
     * fourth, which surfaces as a tab that is empty only after an edit.
     */
    private const DETAIL = [
        'accountManager', 'type', 'sector', 'subsector', 'contacts',
        'team.employee', 'contracts.attachments', 'attachments',
    ];

    public function __construct(
        private readonly AccountService $service,
        private readonly AccountActivityService $activities,
    ) {}

    /**
     * The lists the record screen needs to render its selects.
     *
     * Gated on seeing customers rather than on managing reference data: the
     * form that reads this is used by everyone who edits an account, and
     * gating it on the admin permission would leave them with empty dropdowns
     * and no error to explain them — the same failure the travel wizard had.
     */
    public function lookups(): JsonResponse
    {
        $this->authorize('viewAny', Account::class);

        return response()->json([
            'types'   => AccountType::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'sectors' => AccountSector::where('is_active', true)->orderBy('name')->get(['id', 'name']),

            // Sent whole rather than fetched per sector: the list is small,
            // and one request means changing sector re-filters instantly
            // instead of blanking the field while a fetch is in flight.
            'subsectors' => AccountSubsector::where('is_active', true)->orderBy('name')
                ->get(['id', 'name', 'account_sector_id']),
        ]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Account::class);

        // Someone holding only `view_own` sees their own book. Resolved here
        // from the authenticated user rather than from a request parameter,
        // which the caller controls.
        $limit = $request->user()->hasPermission(Permission::ACCOUNTS_VIEW)
            ? null
            : $request->user()->employeeId();

        $page = $this->service->list(
            filters: $request->only(['search', 'status', 'account_type_id', 'account_sector_id', 'country', 'account_manager_id']),
            perPage: (int) $request->input('per_page', 20),
            limitToManager: $limit,
        );

        /*
         * Totals for the whole book, sent with the page rather than as a second
         * request — the header reads them on first paint, and a strip that
         * arrives after the table is a strip that makes the page jump.
         *
         * Across the whole book, not the current page: "68 customers, 3 with a
         * deal in play" is the sentence somebody opens this list to read, and
         * it must not change when they turn the page.
         */
        return AccountResource::collection($page)->additional([
            'totals' => $this->bookTotals($limit),
        ]);
    }

    /**
     * What the customer book adds up to.
     *
     * `never_contacted` is here because it is the number worth acting on: the
     * activity feed is empty org-wide, so every customer reads as untouched,
     * and a figure saying so is more use than 68 rows each saying "Never".
     *
     * @return array<string, int|float|null>
     */
    private function bookTotals(?int $limitToManager): array
    {
        $q = Account::query()->when($limitToManager, fn ($w, $id) => $w->managedBy($id));

        $openDeal = fn ($w) => $w->where(fn ($d) => $d
            ->whereHas('phase', fn ($p) => $p->whereNull('outcome'))
            ->orWhereNull('opportunity_phase_id'));

        return [
            'customers'       => (clone $q)->count(),
            'active'          => (clone $q)->where('status', 'active')->count(),
            'never_contacted' => (clone $q)->whereDoesntHave('activities')->count(),
            'unowned'         => (clone $q)->whereNull('account_manager_id')->count(),
            'open_pipeline'   => request()->user()?->hasPermission(Permission::PIPELINE_VIEW_COMMERCIALS)
                ? (float) Opportunity::query()
                    ->whereIn('account_id', (clone $q)->select('id'))
                    ->where($openDeal)
                    ->sum('amount')
                : null,
        ];
    }

    public function store(StoreAccountRequest $request): AccountResource
    {
        $this->authorize('create', Account::class);

        $account = $this->service->create(
            data: $request->validated(),
            organizationId: app('current.organization_id'),
            actorId: $request->user()->id,
        );

        return new AccountResource($account->load(['accountManager', 'type', 'sector', 'subsector']));
    }

    public function show(Account $account): AccountResource
    {
        $this->authorize('view', $account);

        return new AccountResource(
            $account->load(self::DETAIL)
        );
    }

    public function update(StoreAccountRequest $request, Account $account): AccountResource
    {
        $this->authorize('update', $account);

        $data = $request->validated();

        // Reassignment is a separate authority: moving a customer between
        // people changes visibility and commission, which editing a phone
        // number does not. Silently dropped rather than refused, so an edit
        // that happens to include the current value still succeeds.
        if (array_key_exists('account_manager_id', $data)
            && (int) $data['account_manager_id'] !== (int) $account->account_manager_id
            && ! $request->user()->can('assign', $account)) {
            unset($data['account_manager_id']);
        }

        return new AccountResource($this->service->update($account, $data));
    }

    /**
     * A client's logo — a small mark shown beside the name. Public disk, like
     * the organisation's own logo, so the returned URL is directly servable.
     * The previous file is removed on replace so old logos don't pile up.
     */
    public function uploadLogo(Request $request, Account $account): AccountResource
    {
        $this->authorize('update', $account);

        $request->validate([
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'],
        ]);

        if ($account->logo_path) {
            Storage::disk('public')->delete($account->logo_path);
        }

        $path = $request->file('logo')->store("account-logos/{$account->id}", 'public');
        $account->update(['logo_path' => $path]);

        return new AccountResource($account->fresh(self::DETAIL));
    }

    public function removeLogo(Account $account): AccountResource
    {
        $this->authorize('update', $account);

        if ($account->logo_path) {
            Storage::disk('public')->delete($account->logo_path);
            $account->update(['logo_path' => null]);
        }

        return new AccountResource($account->fresh(self::DETAIL));
    }

    /**
     * Auto-fetch this client's logo from its contact-email domain. 422 with a
     * reason when there is no corporate domain to fetch from or the fetch fails,
     * so the UI can say why rather than silently doing nothing.
     */
    public function fetchLogo(Account $account, \App\Services\Account\AccountLogoFetcher $fetcher): AccountResource
    {
        $this->authorize('update', $account);

        $result = $fetcher->fetch($account, force: true);
        abort_if($result === 'no_domain', 422, 'No corporate contact email to derive a website from.');
        abort_if($result === 'failed', 422, 'No logo could be fetched for that domain.');

        return new AccountResource($account->fresh(self::DETAIL));
    }

    /**
     * Auto-fetch logos for every client that does not have one yet, from each
     * one's contact-email domain. Returns a tally so the UI can report coverage.
     */
    public function fetchLogos(\App\Services\Account\AccountLogoFetcher $fetcher): JsonResponse
    {
        $this->authorize('create', Account::class);

        $tally = ['fetched' => 0, 'no_domain' => 0, 'failed' => 0];

        Account::whereNull('logo_path')->with('contacts')->chunkById(100, function ($accounts) use ($fetcher, &$tally) {
            foreach ($accounts as $account) {
                $result = $fetcher->fetch($account);
                if (isset($tally[$result])) {
                    $tally[$result]++;
                }
            }
        });

        return response()->json(['data' => $tally]);
    }

    /**
     * Fetch logos from an explicit id → domain map — for well-known clients
     * whose official domain is supplied rather than derived from an email.
     * Returns the outcome per account so the caller sees what resolved.
     */
    public function fetchLogosByDomain(Request $request, \App\Services\Account\AccountLogoFetcher $fetcher): JsonResponse
    {
        $this->authorize('create', Account::class);

        $data = $request->validate([
            'domains'   => ['required', 'array', 'min:1', 'max:500'],
            'domains.*' => ['nullable', 'string', 'max:255'],
            'force'     => ['sometimes', 'boolean'],
        ]);
        $force = (bool) ($data['force'] ?? false);

        $accounts = Account::whereIn('id', array_keys($data['domains']))->get()->keyBy('id');

        $results = [];
        $tally = ['fetched' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($data['domains'] as $id => $domain) {
            $account = $accounts->get((int) $id);
            if (! $account) {
                $results[$id] = 'not_found';
                continue;
            }
            $result = $fetcher->fetchFromDomain($account, $domain, $force);
            $results[$id] = $result;
            $tally[$result === 'fetched' ? 'fetched' : ($result === 'failed' || $result === 'no_domain' ? 'failed' : 'skipped')]++;
        }

        return response()->json(['data' => ['tally' => $tally, 'results' => $results]]);
    }

    public function destroy(Account $account): JsonResponse
    {
        $this->authorize('delete', $account);
        $account->delete();

        return response()->json(['message' => 'Customer archived.']);
    }


    /**
     * The deal figures the Overview shows.
     *
     * Counted on demand rather than stored on the account: every one of these
     * is a fact about opportunities, and a stored copy is wrong from the first
     * deal somebody edits. Won and open are read from the phase, which is the
     * only place that decides whether a deal is closed.
     */
    public function summary(Request $request, Account $account): JsonResponse
    {
        $this->authorize('view', $account);

        $deals = $account->opportunities()->with('phase')->get();

        /*
         * Counts are visible to anybody who may see the customer; values are
         * not. "Three deals in play" is account management; what they are
         * worth is commercial, and aggregating it does not make it less so.
         */
        $showsMoney = $deals->isEmpty()
            || ($request->user()?->can('viewCommercials', $deals->first()) ?? false);

        return response()->json([
            'data' => [
                'commercials_visible' => $showsMoney,
                'total_deals'  => $deals->count(),
                'active_deals' => $deals->where('is_open', true)->count(),
                'closed_won'   => $deals->where('outcome', 'won')->count(),
                'closed_lost'  => $deals->where('outcome', 'lost')->count(),

                // What the won deals were worth, which is the figure people
                // mean by account value.
                'won_value'      => $showsMoney ? (float) $deals->where('outcome', 'won')->sum('amount') : null,
                // What is still in play, kept apart from won so a forecast is
                // never mistaken for revenue.
                'pipeline_value' => $showsMoney ? (float) $deals->where('is_open', true)->sum('amount') : null,

                'open_leads' => $account->leads()->open()->count(),

                /*
                 * Last year's won total, so the headline figure can say which
                 * way it is going. Calendar years, not rolling twelve months:
                 * "vs last year" is how the business talks about it, and a
                 * rolling window would move the comparison every day.
                 */
                'won_value_last_year' => $showsMoney
                    ? (float) $deals->where('outcome', 'won')
                        ->filter(fn ($d) => $d->closed_at?->year === now()->subYear()->year)
                        ->sum('amount')
                    : null,
                'won_value_this_year' => $showsMoney
                    ? (float) $deals->where('outcome', 'won')
                        ->filter(fn ($d) => $d->closed_at?->year === now()->year)
                        ->sum('amount')
                    : null,

                'renewal'      => $this->nextRenewal($account, $showsMoney),
                'last_contact' => $this->lastContact($account),
                'contracts'    => $this->contractTotals($account, $showsMoney),

                // Tab badges. Counted here rather than by loading each
                // collection, so opening the page does not read six tables to
                // print six numbers.
                'counts' => [
                    'contacts'      => $account->contacts()->count(),
                    'contracts'     => $account->contracts()->count(),
                    'activities'    => $account->activities()->count(),
                    'leads'         => $account->leads()->count(),
                    'opportunities' => $deals->count(),
                    'projects'      => $account->projects()->count(),
                    'support'       => $account->supportTickets()->count(),
                    'travels'       => $account->travelRequests()->count(),
                    'team'          => $account->team()->count(),
                ],
            ],
        ]);
    }

    /**
     * The next contract to fall due.
     *
     * The soonest end date that has not passed, among contracts still running.
     * A renewal is a date somebody has to act before, so an expiry already gone
     * is not a renewal — it is a lapse, and saying "renews in -40 days" would
     * be worse than saying nothing.
     */
    private function nextRenewal(Account $account, bool $showsMoney): ?array
    {
        // `reorder()` first: the relation is newest-first, and appending an
        // ascending order to a descending one leaves the descending one
        // winning — which returns the furthest renewal instead of the nearest.
        $contract = $account->contracts()
            ->where('status', 'active')
            ->whereNull('completed_at')
            ->whereDate('end_date', '>=', now()->toDateString())
            ->reorder('end_date')
            ->first();

        if (! $contract) {
            return null;
        }

        return [
            'date'     => $contract->end_date?->toDateString(),
            'days_out' => (int) now()->startOfDay()->diffInDays($contract->end_date, false),
            'name'     => $contract->name,
            'value'    => $showsMoney ? (float) $contract->contract_value : null,
        ];
    }

    /**
     * When somebody last spoke to them, and who.
     *
     * From the activity feed rather than `accounts.last_contact_at`, because
     * the feed also knows the channel and the person — "4 days ago" alone
     * tells you the account is warm but not who to ask about it.
     */
    private function lastContact(Account $account): ?array
    {
        $latest = $account->activities()->with('employee:id,first_name,last_name')->first();

        if (! $latest) {
            return null;
        }

        return [
            'at'     => $latest->occurred_at?->toIso8601String(),
            'type'   => $latest->type,
            'person' => $latest->employee
                ? trim($latest->employee->first_name . ' ' . $latest->employee->last_name)
                : null,
        ];
    }

    /** The three figures the Contracts tab leads with. */
    private function contractTotals(Account $account, bool $showsMoney): array
    {
        $contracts = $account->contracts()->get();

        $nextExpiry = $contracts
            ->where('status', 'active')
            ->whereNull('completed_at')
            ->filter(fn ($c) => $c->end_date && ! $c->end_date->isPast())
            ->sortBy('end_date')
            ->first();

        return [
            'contracted_value' => $showsMoney ? (float) $contracts->sum('contract_value') : null,
            'active'           => $contracts->where('status', 'active')->whereNull('completed_at')->count(),
            'total'            => $contracts->count(),
            'next_expiry'      => $nextExpiry?->end_date?->toDateString(),
        ];
    }

    /**
     * Work delivered for this customer.
     *
     * Progress is counted from the project's own tasks rather than stored:
     * there is no percentage column, and one would immediately disagree with
     * the task board it was supposed to summarise.
     */
    public function projects(Account $account): JsonResponse
    {
        $this->authorize('view', $account);

        $projects = $account->projects()
            ->with('projectLead:id,first_name,last_name')
            ->withCount([
                'tasks',
                'tasks as done_tasks_count' => fn ($q) => $q->whereNotNull('completed_at'),
            ])
            ->orderByRaw("CASE WHEN delivered_on IS NULL THEN 0 ELSE 1 END")
            ->orderBy('deadline')
            ->get();

        return response()->json([
            'data' => $projects->map(fn ($p) => [
                'id'          => $p->id,
                'reference'   => $p->reference,
                'name'        => $p->name,
                'status'      => $p->status,
                'rag'         => $p->rag,
                'deadline'    => $p->deadline?->toDateString(),
                'delivered_on' => $p->delivered_on?->toDateString(),
                'lead'        => $p->projectLead
                    ? trim($p->projectLead->first_name . ' ' . $p->projectLead->last_name)
                    : null,
                'tasks_total' => $p->tasks_count,
                'tasks_done'  => $p->done_tasks_count,
                // Null rather than 0% when there are no tasks: a project nobody
                // has planned is not a project that has done nothing.
                'progress'    => $p->tasks_count > 0
                    ? (int) round($p->done_tasks_count / $p->tasks_count * 100)
                    : null,
            ]),
        ]);
    }

    /**
     * Problems raised about that work, and how well they are being answered.
     *
     * The four figures above the table are the ones a customer asks about in a
     * review: how many are open, how many have missed their promise, how fast
     * we pick up, and what they thought of it.
     */
    public function supportTickets(Account $account): JsonResponse
    {
        $this->authorize('view', $account);

        $tickets = $account->supportTickets()
            ->with(['status:id,name,state', 'priority:id,name', 'assignee:id,name', 'sla', 'survey'])
            ->latest('updated_at')
            ->limit(100)
            ->get();

        $open = $tickets->filter(fn ($t) => $t->status?->state === 'open');

        // Breaching means the resolution promise has passed and the ticket is
        // still not resolved. A ticket answered late is a missed target, not a
        // live breach — those are two different conversations.
        $breaching = $open->filter(
            fn ($t) => $t->sla?->resolution_due_at
                && $t->sla->resolution_due_at->isPast()
                && $t->sla->resolution_met !== true,
        );

        /*
         * A response stamped within a minute of the ticket being raised was
         * not measured, it was imported.
         *
         * 102 of 300 imported tickets carry `first_response_at == created_at`
         * outright, and on the largest account every remaining "response" lands
         * between 1 and 25 seconds — the importer writing the two fields as it
         * walked the rows. Counting those gives a headline of "0m", which reads
         * as an instant desk and is simply false.
         *
         * A minute is a floor, not a claim that nobody answers faster. Losing
         * the rare genuine 40-second reply costs little; publishing a fabricated
         * zero costs the figure its meaning.
         */
        $responded = $tickets->filter(
            fn ($t) => $t->first_response_at && $t->created_at
                && $t->created_at->diffInSeconds($t->first_response_at) >= 60,
        );

        $avgMinutes = $responded->isEmpty()
            ? null
            : (int) round($responded->avg(fn ($t) => $t->created_at->diffInMinutes($t->first_response_at)));

        $rated = $tickets->filter(fn ($t) => $t->survey?->rating !== null);

        return response()->json([
            'stats' => [
                'open'                 => $open->count(),
                'breaching'            => $breaching->count(),
                'avg_response_minutes' => $avgMinutes,
                'satisfaction'         => $rated->isEmpty() ? null : round($rated->avg(fn ($t) => $t->survey->rating), 1),
                'rated'                => $rated->count(),
                // How many the average is drawn from, so a figure from three
                // tickets is not read as a figure from three hundred.
                'responded'            => $responded->count(),
            ],
            'data' => $tickets->map(fn ($t) => [
                'id'         => $t->id,
                'reference'  => $t->reference,
                'subject'    => $t->subject,
                'status'     => $t->status?->name,
                'state'      => $t->status?->state,
                'priority'   => $t->priority?->name,
                'assignee'   => $t->assignee?->name,
                'updated_at' => $t->updated_at?->toIso8601String(),
                'breaching'  => $breaching->contains($t),
            ]),
        ]);
    }

    /**
     * Watch a customer, or stop.
     *
     * A personal bookmark, so it hangs off `view` — being told about an account
     * you may read is not a further privilege.
     */
    public function follow(Request $request, Account $account): JsonResponse
    {
        $this->authorize('view', $account);

        $account->followers()->syncWithoutDetaching([$request->user()->id]);

        return response()->json(['data' => ['following' => true]]);
    }

    public function unfollow(Request $request, Account $account): JsonResponse
    {
        $this->authorize('view', $account);

        $account->followers()->detach($request->user()->id);

        return response()->json(['data' => ['following' => false]]);
    }

    /**
     * Trips taken for this customer.
     *
     * The link has existed since Travel was built, but only ever read from the
     * trip's side — so "what has this journey cost us on this account" had no
     * answer without going through every request.
     */
    public function travels(Account $account): JsonResponse
    {
        $this->authorize('view', $account);

        $trips = $account->travelRequests()
            ->with(['employee.designation', 'destination'])
            ->latest('departure_date')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $trips->map(fn ($t) => [
                'id'             => $t->id,
                'reference'      => $t->reference,
                'traveller'      => $t->employee?->full_name,
                'role'           => $t->employee?->designation?->title,
                'destination'    => $t->destination?->name,
                'departure_date' => $t->departure_date?->toDateString(),
                'return_date'    => $t->return_date?->toDateString(),
                'status'         => $t->status,
                'travel_type'    => $t->travel_type,
            ]),
        ]);
    }

    // ── Team ──────────────────────────────────────────────────────────────
    //
    // Who works the account, as distinct from who owns it. An owner field
    // alone cannot answer "who deals with this client".

    public function storeTeamMember(Request $request, Account $account): AccountResource
    {
        // Adding somebody to a team is a change to the account, so it needs
        // the same authority as editing one.
        $this->authorize('update', $account);

        $data = $request->validate([
            'employee_id' => ['required', 'integer',
                Rule::exists('employees', 'id')->where('primary_organization_id', $account->organization_id)],
            'role' => ['nullable', 'string', 'max:120'],
        ]);

        AccountTeamMember::updateOrCreate(
            ['account_id' => $account->id, 'employee_id' => $data['employee_id']],
            [
                'organization_id' => $account->organization_id,
                'role'            => $data['role'] ?? null,
                'added_by'        => $request->user()->id,
            ],
        );

        return new AccountResource($account->fresh(self::DETAIL));
    }

    public function destroyTeamMember(Account $account, AccountTeamMember $member): AccountResource
    {
        $this->authorize('update', $account);
        abort_unless($member->account_id === $account->id, 404);

        $member->delete();

        return new AccountResource($account->fresh(self::DETAIL));
    }

    // ── Activities ────────────────────────────────────────────────────────
    //
    // The timeline, and the only thing that moves `last_contact_at`.

    public function activities(Account $account): AnonymousResourceCollection
    {
        $this->authorize('view', $account);

        return AccountActivityResource::collection(
            $account->activities()->with(['employee', 'author'])->limit(100)->get()
        );
    }

    public function storeActivity(Request $request, Account $account): AccountActivityResource
    {
        // Logging a call is not editing the customer: anybody who deals with
        // them should be able to say that they spoke to them.
        $this->authorize('view', $account);

        $data = $request->validate([
            'type'        => ['sometimes', Rule::in(AccountActivity::TYPES)],
            'body'        => ['required', 'string', 'max:5000'],
            // Accepted so a call can be logged the following morning and still
            // land on the day it happened.
            'occurred_at' => ['sometimes', 'date', 'before_or_equal:now'],
        ]);

        $activity = $this->activities->log(
            $account, $data, $request->user()->id, $request->user()->employeeId(),
        );

        return new AccountActivityResource($activity->load(['employee', 'author']));
    }

    // ── Contracts ─────────────────────────────────────────────────────────
    //
    // Ported from Plmv2. Gated on editing the customer rather than on a
    // permission of their own: the people who keep a customer record straight
    // are the people who know when its support agreement runs out.

    public function storeContract(StoreContractRequest $request, Account $account): AccountContractResource
    {
        $this->authorize('update', $account);

        return new AccountContractResource($account->contracts()->create(
            $request->validated() + [
                'reference' => ReferenceSequence::next('CT', 'account_contracts', $account->organization_id),
                // Taken from the account, not from the request context: a
                // contract must belong to whoever owns the customer, even if
                // the two could somehow disagree.
                'organization_id' => $account->organization_id,
                'created_by'      => $request->user()->id,
            ]
        ));
    }

    /**
     * Mark a contract done.
     *
     * Its own action rather than an edit of the status field: finishing a
     * contract stamps when it finished, and a plain edit would let the two
     * disagree.
     */
    public function completeContract(Request $request, Account $account, AccountContract $contract): AccountContractResource
    {
        $this->authorize('update', $account);
        abort_unless($contract->account_id === $account->id, 404);

        $contract->update(['status' => 'completed', 'completed_at' => now()]);

        return new AccountContractResource($contract->fresh('attachments'));
    }

    public function updateContract(StoreContractRequest $request, Account $account, AccountContract $contract): AccountContractResource
    {
        $this->authorize('update', $account);
        abort_unless($contract->account_id === $account->id, 404);

        $contract->update($request->validated());

        return new AccountContractResource($contract->fresh());
    }

    public function destroyContract(Account $account, AccountContract $contract): JsonResponse
    {
        $this->authorize('update', $account);
        abort_unless($contract->account_id === $account->id, 404);

        $contract->delete();

        return response()->json(['message' => 'Contract removed.']);
    }

    // ── Attachments ───────────────────────────────────────────────────────
    //
    // Filed against the customer, or against one of their contracts. Never
    // publicly addressable: a signed contract carries commercial terms and
    // signatures, which is the same reason candidate CVs stopped being.

    public function storeAttachment(Request $request, Account $account): AccountAttachmentResource
    {
        $this->authorize('update', $account);

        $data = $request->validate([
            'file'          => ['required', 'file', 'max:20480', 'mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg'],
            'document_type' => ['sometimes', Rule::in(AccountAttachment::DOCUMENT_TYPES)],
            'label'         => ['nullable', 'string', 'max:255'],
            'expiry_date'   => ['nullable', 'date'],

            // Scoped to this customer's own contracts, so a document cannot be
            // filed against somebody else's agreement.
            'account_contract_id' => ['nullable', 'integer',
                Rule::exists('account_contracts', 'id')->where('account_id', $account->id)],
        ]);

        $file = $request->file('file');

        // Encrypted before it reaches storage. A signed contract carries
        // commercial terms and signatures; blob-level encryption protects a
        // stolen disk and nothing else.
        $path = DocumentVault::store($file, 'account_documents', "accounts/{$account->id}");

        $attachment = $account->attachments()->create([
            'organization_id'     => $account->organization_id,
            'account_contract_id' => $data['account_contract_id'] ?? null,
            'document_type'       => $data['document_type'] ?? 'other',
            'label'               => $data['label'] ?? null,
            'original_name'       => $file->getClientOriginalName(),
            'storage_path'        => $path,
            'mime_type'           => $file->getClientMimeType(),
            'size_bytes'          => $file->getSize(),
            'expiry_date'         => $data['expiry_date'] ?? null,
            'uploaded_by'         => $request->user()->id,
        ]);

        return new AccountAttachmentResource($attachment);
    }

    public function downloadAttachment(Account $account, AccountAttachment $attachment): StreamedResponse
    {
        $this->authorize('view', $account);
        abort_unless($attachment->account_id === $account->id, 404);

        return DocumentVault::download(
            'account_documents', $attachment->storage_path,
            $attachment->original_name, $attachment->mime_type,
        );
    }

    public function destroyAttachment(Account $account, AccountAttachment $attachment): JsonResponse
    {
        $this->authorize('update', $account);
        abort_unless($attachment->account_id === $account->id, 404);

        // Soft-deleted, and the file is left alone. Taking a contract off the
        // screen should not destroy the evidence that it was signed.
        $attachment->delete();

        return response()->json(['message' => 'Document removed.']);
    }

    // ── Contacts ──────────────────────────────────────────────────────────

    public function storeContact(StoreContactRequest $request, Account $account): AccountContactResource
    {
        $this->authorize('update', $account);

        return new AccountContactResource(
            $this->service->addContact($account, $request->validated(), $request->user()->id)
        );
    }

    public function updateContact(StoreContactRequest $request, Account $account, AccountContact $contact): AccountContactResource
    {
        $this->authorize('update', $account);
        abort_unless($contact->account_id === $account->id, 404);

        $data = $request->validated();

        /*
         * You cannot edit what you cannot read. A caller without
         * accounts.view_contacts received these fields MASKED — letting the
         * update through would overwrite the real address with the mask the
         * moment they save an unrelated edit to the same card.
         */
        if (! ($request->user()?->hasPermission(\App\Support\Authorization\Permission::ACCOUNTS_VIEW_CONTACTS) ?? false)) {
            unset($data['email'], $data['phone'], $data['alternate_phone']);
        }

        return new AccountContactResource($this->service->updateContact($contact, $data));
    }

    public function destroyContact(Account $account, AccountContact $contact): JsonResponse
    {
        $this->authorize('update', $account);
        abort_unless($contact->account_id === $account->id, 404);

        $this->service->deleteContact($contact);

        return response()->json(['message' => 'Contact removed.']);
    }
}
