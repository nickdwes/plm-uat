<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\Account\AccountContract;
use App\Models\Support\ContractEngineer;
use App\Models\Support\ContractSupport;
use App\Models\Support\Engineer;
use App\Models\Support\Escalation;
use App\Models\Support\ServiceCategory;
use App\Models\Support\Ticket;
use App\Models\Support\TicketStatus;
use App\Services\Support\EscalationService;
use App\Services\Support\RoutingEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The service desk analyst's work, and the structures behind it.
 *
 * Triage, reassignment, escalation and the contract support structure that
 * decides where a ticket goes in the first place. Kept in its own controller
 * because it is a different job from answering a ticket — the analyst knows the
 * contracts, the engineer knows the technology, and conflating them is how the
 * desk ends up technically responsible for everything.
 */
class RoutingController extends Controller
{
    public function __construct(
        private readonly RoutingEngine $routing,
        private readonly EscalationService $escalations,
    ) {}

    /**
     * Who could take this ticket, ranked, with their workload.
     *
     * Suggestions and not an assignment. Where several engineers are equally
     * placed the choice belongs to a person, who knows about the leave nobody
     * recorded and the customer who will not work with one of them.
     */
    public function suggest(int $ticket): JsonResponse
    {
        $this->authorize('triage', Ticket::class);

        $record = Ticket::findOrFail($ticket);

        return response()->json([
            'suggestions' => $this->routing->suggest($record),
            'current'     => [
                'assigned_to'      => $record->assigned_to,
                'support_team_id'  => $record->support_team_id,
                'contract'         => $record->contract?->name,
            ],
        ]);
    }

    /** Run the routing rules again — after triage has set a category. */
    public function reroute(Request $request, int $ticket): JsonResponse
    {
        $this->authorize('triage', Ticket::class);

        $record = Ticket::findOrFail($ticket);

        /*
         * Forced only when asked. Re-routing a ticket somebody is working on
         * because a rule changed is exactly the behaviour the structure exists
         * to prevent.
         */
        $result = $this->routing->route($record, force: $request->boolean('force'));

        return response()->json(['result' => $result, 'data' => $record->fresh(['assignee', 'team'])]);
    }

    public function reassign(Request $request, int $ticket): JsonResponse
    {
        $this->authorize('reassign', Ticket::class);

        $data = $request->validate([
            'assigned_to'     => ['nullable', 'integer', 'exists:users,id'],
            'support_team_id' => ['nullable', 'integer', 'exists:support_teams,id'],
            // Required, because "who sent this to me and why" is the first
            // question the next person asks.
            'reason'          => ['required', 'string', 'max:500'],
        ]);

        $record = Ticket::findOrFail($ticket);

        return response()->json([
            'data' => $this->escalations->reassign(
                $record,
                $data['assigned_to'] ?? null,
                $data['support_team_id'] ?? null,
                $data['reason'],
                $request->user(),
            ),
        ]);
    }

    // ── Escalation ────────────────────────────────────────────────────────────

    public function escalate(Request $request, int $ticket): JsonResponse
    {
        $this->authorize('escalate', Ticket::class);

        $data = $request->validate([
            'level'      => ['required', 'in:specialist,team_lead,business_unit,vendor,service_desk'],
            'reason'     => ['required', 'string', 'max:2000'],
            'to_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'to_team_id' => ['nullable', 'integer', 'exists:support_teams,id'],
        ]);

        try {
            $escalation = $this->escalations->escalate(Ticket::findOrFail($ticket), $data, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $escalation], 201);
    }

    public function resolveEscalation(Request $request, int $ticket, int $escalation): JsonResponse
    {
        $this->authorize('escalate', Ticket::class);

        $data = $request->validate(['outcome' => ['required', 'string', 'max:2000']]);

        $record = Escalation::where('support_ticket_id', $ticket)->findOrFail($escalation);

        try {
            return response()->json([
                'data' => $this->escalations->resolve($record, $data['outcome'], $request->user()),
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    // ── The structure behind it ───────────────────────────────────────────────

    /** Everything the contract support screen needs. */
    public function contracts(): JsonResponse
    {
        $this->authorize('manageContracts', Ticket::class);

        return response()->json([
            'contracts' => AccountContract::with('account:id,name')
                ->orderBy('name')->get()
                ->map(function (AccountContract $c) {
                    $support = ContractSupport::where('account_contract_id', $c->id)->first();

                    return [
                        'id'      => $c->id,
                        'name'    => $c->name,
                        'account' => $c->account?->name,
                        'status'  => $c->status,
                        'support' => $support,
                        'engineers' => ContractEngineer::where('account_contract_id', $c->id)
                            ->with(['engineer:id,name', 'category:id,name'])->get(),
                    ];
                }),

            'categories' => ServiceCategory::with('practice:id,name')
                ->where('is_active', true)->orderBy('position')->get(),

            'engineers' => Engineer::with(['user:id,name', 'practice:id,name'])
                ->where('is_active', true)->get(),
        ]);
    }

    public function saveContractSupport(Request $request, int $contract): JsonResponse
    {
        $this->authorize('manageContracts', Ticket::class);

        $data = $request->validate([
            'primary_team_id'      => ['nullable', 'integer', 'exists:support_teams,id'],
            'escalation_team_id'   => ['nullable', 'integer', 'exists:support_teams,id'],
            'service_desk_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'vendor_contact_name'  => ['nullable', 'string', 'max:160'],
            'vendor_contact_email' => ['nullable', 'email', 'max:200'],
            'notes'                => ['nullable', 'string', 'max:500'],
        ]);

        AccountContract::findOrFail($contract);

        $support = ContractSupport::updateOrCreate(
            ['account_contract_id' => $contract],
            $data + ['organization_id' => app('current.organization_id')],
        );

        return response()->json(['data' => $support], 201);
    }

    public function saveContractEngineer(Request $request, int $contract): JsonResponse
    {
        $this->authorize('manageContracts', Ticket::class);

        $data = $request->validate([
            'user_id'             => ['required', 'integer', 'exists:users,id'],
            'role'                => ['required', 'in:primary,secondary'],
            'service_category_id' => ['nullable', 'integer', 'exists:support_service_categories,id'],
            'notes'               => ['nullable', 'string', 'max:255'],
        ]);

        AccountContract::findOrFail($contract);

        $engineer = ContractEngineer::updateOrCreate(
            [
                'account_contract_id' => $contract,
                'user_id'             => $data['user_id'],
                'service_category_id' => $data['service_category_id'] ?? null,
            ],
            $data + ['organization_id' => app('current.organization_id')],
        );

        return response()->json(['data' => $engineer->load(['engineer:id,name', 'category:id,name'])], 201);
    }

    public function removeContractEngineer(int $contract, int $engineer): JsonResponse
    {
        $this->authorize('manageContracts', Ticket::class);

        ContractEngineer::where('account_contract_id', $contract)->findOrFail($engineer)->delete();

        return response()->json(['deleted' => true]);
    }

    // ── Engineers and categories ──────────────────────────────────────────────

    public function saveEngineer(Request $request): JsonResponse
    {
        $this->authorize('manageContracts', Ticket::class);

        $data = $request->validate([
            'user_id'            => ['required', 'integer', 'exists:users,id'],
            'org_practice_id'    => ['nullable', 'integer', 'exists:org_practices,id'],
            'skills'             => ['nullable', 'array'],
            'skills.*'           => ['string', 'max:60'],
            'accepts_assignment' => ['boolean'],
            'is_active'          => ['boolean'],
        ]);

        $engineer = Engineer::updateOrCreate(
            ['organization_id' => app('current.organization_id'), 'user_id' => $data['user_id']],
            $data,
        );

        return response()->json(['data' => $engineer->load(['user:id,name', 'practice:id,name'])], 201);
    }

    public function saveCategory(Request $request): JsonResponse
    {
        $this->authorize('manageConfig', Ticket::class);

        $data = $request->validate([
            'id'              => ['nullable', 'integer', 'exists:support_service_categories,id'],
            'name'            => ['required', 'string', 'max:120'],
            'org_practice_id' => ['nullable', 'integer', 'exists:org_practices,id'],
            'is_technical'    => ['boolean'],
            'position'        => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);

        $category = ServiceCategory::updateOrCreate(
            ['id' => $data['id'] ?? null],
            collect($data)->except('id')->all() + [
                'organization_id' => app('current.organization_id'),
                'slug'            => \Str::slug($data['name']),
            ],
        );

        return response()->json(['data' => $category], 201);
    }

    /**
     * The analyst's board: what needs placing, and what needs chasing.
     *
     * Unassigned work and tickets at risk are the two things the desk exists to
     * catch, so they are one call rather than something assembled from filters
     * somebody has to remember to apply.
     */
    public function deskBoard(Request $request): JsonResponse
    {
        $this->authorize('triage', Ticket::class);

        $base = fn () => Ticket::with(['status', 'priority', 'type', 'account', 'team', 'assignee', 'sla'])
            ->whereHas('status', fn ($q) => $q->whereIn('state', ['open', 'pending']));

        return response()->json([
            // Nobody has it yet.
            'unassigned' => $base()->whereNull('assigned_to')->orderBy('id')->limit(50)->get(),

            // Promised soon, or already late.
            'at_risk' => $base()
                ->whereHas('sla', fn ($q) => $q->whereNull('resolution_met')
                    ->where('resolution_due_at', '<', now()->addHours(4)))
                ->orderBy('id')->limit(50)->get(),

            // Waiting on somebody else — the desk's job is to chase these.
            'waiting' => $base()
                ->whereHas('status', fn ($q) => $q->where('state', 'pending'))
                ->orderBy('updated_at')->limit(50)->get(),

            'mine' => $base()->where('service_desk_user_id', $request->user()?->id)
                ->orderBy('id')->limit(50)->get(),

            'open_escalations' => Escalation::whereNull('resolved_at')
                ->with(['ticket:id,reference,subject', 'to:id,name', 'by:id,name'])
                ->orderByDesc('escalated_at')->limit(50)->get(),

            'counts'         => $this->deskCounts(),
            'by_type'        => $this->byType(),
            'active_tickets' => $base()->orderByDesc('updated_at')->limit(15)->get(),
            'top_assignees'  => $this->topAssignees(),
        ]);
    }

    /**
     * How the open load breaks down by kind — incident, service request,
     * change, and the rest — so the desk can see what it is actually being
     * asked for, not just how much. Untyped tickets are counted under a null
     * bucket rather than dropped.
     */
    private function byType(): \Illuminate\Support\Collection
    {
        return Ticket::whereHas('status', fn ($q) => $q->whereIn('state', ['open', 'pending']))
            ->selectRaw('ticket_type_id, COUNT(*) as tickets_count')
            ->groupBy('ticket_type_id')
            ->with('type:id,name')
            ->get()
            ->map(fn ($row) => [
                'type'          => $row->type?->name ?? 'Unclassified',
                'tickets_count' => (int) $row->tickets_count,
            ])
            ->sortByDesc('tickets_count')
            ->values();
    }

    /**
     * The four headline numbers. Three of the four (open, in progress,
     * closed) partition every ticket exhaustively by TicketStatus::STATE_*;
     * SLA violations is not a fourth state but a condition layered over the
     * first two — a ticket is open *and* breached, never breached instead of
     * open. Scoped to non-terminal tickets: a breach on something already
     * closed happened, but it isn't waiting on anyone to act on it any more.
     */
    private function deskCounts(): array
    {
        $stateCount = fn (array $states) => Ticket::whereHas(
            'status', fn ($q) => $q->whereIn('state', $states)
        )->count();

        return [
            'open'           => $stateCount([TicketStatus::STATE_OPEN]),
            'in_progress'    => $stateCount([TicketStatus::STATE_PENDING]),
            'closed'         => $stateCount([TicketStatus::STATE_RESOLVED, TicketStatus::STATE_CLOSED]),
            'sla_violations' => Ticket::whereHas('status', fn ($q) => $q->whereIn('state', ['open', 'pending']))
                ->whereHas('sla', fn ($q) => $q->where('response_met', false)->orWhere('resolution_met', false))
                ->count(),
        ];
    }

    /** Who is actually carrying the open load right now, ranked by count. */
    private function topAssignees(): \Illuminate\Support\Collection
    {
        return Ticket::whereHas('status', fn ($q) => $q->whereIn('state', ['open', 'pending']))
            ->whereNotNull('assigned_to')
            ->selectRaw('assigned_to, COUNT(*) as tickets_count')
            ->groupBy('assigned_to')
            ->orderByDesc('tickets_count')
            ->with('assignee:id,name')
            ->limit(8)
            ->get()
            ->map(fn ($row) => [
                'user_id'       => $row->assigned_to,
                'name'          => $row->assignee?->name ?? 'Unknown',
                'tickets_count' => (int) $row->tickets_count,
            ]);
    }
}
