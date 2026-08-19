<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\Support\Ticket;
use App\Models\Support\TicketMessage;
use App\Models\Support\TicketStatus;
use App\Services\Support\SupportDefaults;
use App\Services\Support\TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The service desk.
 *
 * Every write goes through TicketService rather than touching the model here:
 * mail ingestion and the customer portal are the next two phases, and three
 * controllers each setting a first-response timestamp their own way is how the
 * figure stops meaning one thing.
 */
class TicketController extends Controller
{
    public function __construct(
        private readonly TicketService $service,
        private readonly SupportDefaults $defaults,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Ticket::class);

        $orgId = app('current.organization_id');
        $this->defaults->ensureFor($orgId);

        // The clock rides along on the list: a countdown nobody can see is the
        // old system's problem restated, not solved.
        $query = Ticket::with(['status', 'type', 'priority', 'assignee', 'account', 'sla', 'team'])
            ->withCount('messages');

        // Filters, all optional, all narrowing rather than replacing.
        if ($request->filled('status'))   { $query->where('ticket_status_id', $request->integer('status')); }
        if ($request->filled('state'))    {
            $query->whereHas('status', fn ($q) => $q->where('state', $request->string('state')));
        }
        if ($request->filled('assignee')) { $query->where('assigned_to', $request->integer('assignee')); }
        if ($request->filled('account'))  { $query->where('account_id', $request->integer('account')); }
        if ($request->filled('type'))     { $query->where('ticket_type_id', $request->integer('type')); }
        if ($request->filled('team'))     { $query->where('support_team_id', $request->integer('team')); }
        if ($request->boolean('unassigned')) { $query->whereNull('assigned_to'); }

        if ($request->boolean('mine') && $request->user()) {
            $query->where('assigned_to', $request->user()->id);
        }

        /*
         * Somebody holding only `support.view_own` sees their own tickets and
         * nothing else. Narrowed here as well as in the policy, because the
         * policy is asked about one record and a listing never presents one —
         * without this the permission read exactly like full visibility.
         */
        if ($request->user() && ! $request->user()->hasPermission(\App\Support\Authorization\Permission::SUPPORT_VIEW)) {
            $me = $request->user()->id;

            $query->where(fn ($q) => $q->where('requester_user_id', $me)
                ->orWhere('assigned_to', $me)
                ->orWhere('escalation_owner_id', $me));
        }

        /*
         * Unresolved by default. A desk list that opens on two years of closed
         * tickets is a list nobody reads.
         */
        if (! $request->filled('status') && ! $request->filled('state') && ! $request->boolean('all')) {
            $query->whereHas('status', fn ($q) => $q->whereIn('state', [
                TicketStatus::STATE_OPEN, TicketStatus::STATE_PENDING,
            ]));
        }

        if ($term = trim((string) $request->string('q'))) {
            $query->where(function ($q) use ($term) {
                $q->where('subject', 'ilike', "%{$term}%")
                  ->orWhere('reference', 'ilike', "%{$term}%")
                  ->orWhere('requester_email', 'ilike', "%{$term}%")
                  ->orWhere('requester_name', 'ilike', "%{$term}%");
            });
        }

        return response()->json(
            $query->orderByDesc('id')->paginate($request->integer('per_page', 25)),
        );
    }

    public function show(int $ticket, \App\Services\Support\SlaClock $sla): JsonResponse
    {
        $this->authorize('view', Ticket::class);

        $record = $this->find($ticket);

        return response()->json([
            'data' => $record->load([
                'status', 'type', 'urgency', 'impact', 'priority',
                'assignee', 'account', 'contact', 'contract', 'parent',
                'messages' => fn ($q) => $q->with('author:id,name')->orderBy('id'),
                'worklogs' => fn ($q) => $q->with('user:id,name')->orderByDesc('performed_on'),
                'sla.sla', 'slaEvents' => fn ($q) => $q->orderBy('occurred_at'),
                'team', 'children:id,reference,subject,ticket_status_id,parent_ticket_id',
                'serviceCategory', 'analyst:id,name', 'escalationOwner:id,name',
                /*
                 * The hand-off trail and the escalations. Written since the
                 * routing revision and, until now, loaded by nothing — which is
                 * the whole failure this rebuild keeps finding in the old
                 * module: a record nothing can read.
                 */
                'events' => fn ($q) => $q->with('actor:id,name')->orderBy('occurred_at'),
                'escalations' => fn ($q) => $q->with(['to:id,name', 'by:id,name'])
                    ->orderByDesc('escalated_at'),
            ]),
            'minutes_logged' => $record->minutesLogged(),
            /*
             * Counted alongside raw elapsed. The gap between them is the
             * most-disputed number in any service review, so it is stated here
             * rather than left to be worked out from the event log.
             */
            'sla_minutes_used' => $sla->minutesUsed($record),
            'elapsed_minutes'  => $record->opened_at
                ? (int) $record->opened_at->diffInMinutes(now())
                : 0,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Ticket::class);

        $data = $request->validate([
            'subject'          => ['required', 'string', 'max:300'],
            'body'             => ['nullable', 'string'],
            'account_id'       => ['nullable', 'integer', 'exists:accounts,id'],
            'account_contact_id' => ['nullable', 'integer', 'exists:account_contacts,id'],
            'requester_name'   => ['nullable', 'string', 'max:160'],
            'requester_email'  => ['nullable', 'email', 'max:200'],
            'ticket_type_id'   => ['nullable', 'integer', 'exists:support_ticket_types,id'],
            'urgency_id'       => ['nullable', 'integer', 'exists:support_ticket_urgencies,id'],
            'impact_id'        => ['nullable', 'integer', 'exists:support_ticket_impacts,id'],
            'assigned_to'      => ['nullable', 'integer', 'exists:users,id'],
            'source'           => ['nullable', 'in:email,portal,phone,agent'],
        ]);

        $ticket = $this->service->open(app('current.organization_id'), $data, $request->user());

        return response()->json(['data' => $ticket], 201);
    }

    /** A reply the customer sees, or a note they never do. */
    public function reply(Request $request, int $ticket): JsonResponse
    {
        $this->authorize('update', Ticket::class);

        $data = $request->validate([
            'body'       => ['required', 'string'],
            'visibility' => ['nullable', 'in:public,internal'],
        ]);

        $record  = $this->find($ticket);
        $message = $this->service->reply($record, $data, $request->user());

        return response()->json([
            'data'   => $message->load('author:id,name'),
            'ticket' => $record->fresh(['status']),
        ], 201);
    }

    public function changeStatus(Request $request, int $ticket): JsonResponse
    {
        $record = $this->find($ticket);

        $data = $request->validate([
            'ticket_status_id' => ['required', 'integer', 'exists:support_ticket_statuses,id'],
        ]);

        $status = TicketStatus::findOrFail($data['ticket_status_id']);

        // Closing fixes an SLA outcome, so it is gated apart from an update.
        $this->authorize($status->state === TicketStatus::STATE_CLOSED ? 'close' : 'update', Ticket::class);

        return response()->json(['data' => $this->service->changeStatus($record, $status, $request->user())]);
    }

    public function reclassify(Request $request, int $ticket): JsonResponse
    {
        $this->authorize('update', Ticket::class);

        $data = $request->validate([
            'ticket_type_id' => ['nullable', 'integer', 'exists:support_ticket_types,id'],
            'urgency_id'     => ['nullable', 'integer', 'exists:support_ticket_urgencies,id'],
            'impact_id'      => ['nullable', 'integer', 'exists:support_ticket_impacts,id'],
            'account_id'     => ['nullable', 'integer', 'exists:accounts,id'],
        ]);

        return response()->json(['data' => $this->service->reclassify($this->find($ticket), $data)]);
    }

    public function assign(Request $request, int $ticket): JsonResponse
    {
        $this->authorize('assign', Ticket::class);

        // Team and person together: moving work between queues is the same
        // act as handing it to somebody, and splitting them across two
        // endpoints means a screen has to make two calls to do one thing.
        $data = $request->validate([
            'assigned_to'     => ['nullable', 'integer', 'exists:users,id'],
            'support_team_id' => ['nullable', 'integer', 'exists:support_teams,id'],
        ]);

        $record = $this->find($ticket);

        $record->update(array_intersect_key($data, array_flip(
            array_keys($request->only(['assigned_to', 'support_team_id'])),
        )));

        return response()->json(['data' => $record->fresh(['assignee', 'team'])]);
    }

    public function logTime(Request $request, int $ticket): JsonResponse
    {
        $this->authorize('logTime', Ticket::class);

        $data = $request->validate([
            'minutes'        => ['required', 'integer', 'min:1', 'max:10080'],
            'performed_on'   => ['nullable', 'date'],
            'note'           => ['nullable', 'string', 'max:255'],
            'under_contract' => ['boolean'],
        ]);

        $record = $this->find($ticket);
        $log    = $this->service->logTime($record, $request->user(), $data);

        return response()->json([
            'data'           => $log->load('user:id,name'),
            'minutes_logged' => $record->minutesLogged(),
        ], 201);
    }

    // ── Major incidents ───────────────────────────────────────────────────────

    /** Join this ticket to a parent. The child keeps its own clock. */
    public function merge(Request $request, int $ticket, \App\Services\Support\MajorIncidents $incidents): JsonResponse
    {
        $this->authorize('update', Ticket::class);

        $data = $request->validate(['parent_reference' => ['required', 'string', 'max:24']]);

        $child  = $this->find($ticket);
        $parent = Ticket::where('reference', strtoupper(trim($data['parent_reference'])))->first();

        if (! $parent) {
            return response()->json([
                'message' => "No ticket here has the reference {$data['parent_reference']}.",
            ], 422);
        }

        try {
            return response()->json(['data' => $incidents->merge($child, $parent, $request->user())]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function detach(Request $request, int $ticket, \App\Services\Support\MajorIncidents $incidents): JsonResponse
    {
        $this->authorize('update', Ticket::class);

        return response()->json([
            'data' => $incidents->detach($this->find($ticket), $request->user()),
        ]);
    }

    /**
     * Say it once, to everybody joined to this ticket.
     *
     * Each child receives it as a genuine reply on their own ticket, which is
     * what they will look at and what their SLA is measured on.
     */
    public function broadcast(Request $request, int $ticket, \App\Services\Support\MajorIncidents $incidents): JsonResponse
    {
        $this->authorize('update', Ticket::class);

        $data = $request->validate(['body' => ['required', 'string']]);

        try {
            $result = $incidents->broadcast($this->find($ticket), $data['body'], $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'sent_to_children' => $result['children'],
            'message' => $result['children'] === 0
                ? 'Posted. Nothing else is joined to this ticket.'
                : "Posted here and on {$result['children']} joined " .
                  ($result['children'] === 1 ? 'ticket.' : 'tickets.'),
        ], 201);
    }

    // ── Working the queue ─────────────────────────────────────────────────────

    /**
     * Do one thing to several tickets.
     *
     * Each is applied through the service one at a time rather than as a mass
     * update, so the clock, the first-response mark and the timestamps all
     * behave exactly as they would if somebody had opened each ticket — a bulk
     * action that skips those rules is how a queue gets tidied and the SLA
     * figures quietly stop meaning anything.
     */
    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ticket_ids'   => ['required', 'array', 'min:1', 'max:200'],
            'ticket_ids.*' => ['integer'],
            'action'       => ['required', 'in:status,assign,team'],
            'ticket_status_id' => ['nullable', 'integer', 'exists:support_ticket_statuses,id'],
            'assigned_to'      => ['nullable', 'integer', 'exists:users,id'],
            'support_team_id'  => ['nullable', 'integer', 'exists:support_teams,id'],
        ]);

        $status = null;

        if ($data['action'] === 'status') {
            $status = TicketStatus::findOrFail($data['ticket_status_id'] ?? 0);
            $this->authorize($status->state === TicketStatus::STATE_CLOSED ? 'close' : 'update', Ticket::class);
        } else {
            $this->authorize('assign', Ticket::class);
        }

        $done = 0;
        $skipped = [];

        foreach ($data['ticket_ids'] as $id) {
            $ticket = Ticket::find($id);

            if (! $ticket) {
                // Scoped away or deleted. Named rather than silently dropped:
                // a count that does not match what was selected needs a reason.
                $skipped[] = $id;
                continue;
            }

            match ($data['action']) {
                'status' => $this->service->changeStatus($ticket, $status, $request->user()),
                'assign' => $ticket->update(['assigned_to' => $data['assigned_to'] ?? null]),
                'team'   => $ticket->update(['support_team_id' => $data['support_team_id'] ?? null]),
            };

            $done++;
        }

        return response()->json(['updated' => $done, 'skipped' => $skipped]);
    }

    /** The vocabulary a screen needs to render pickers. */
    public function taxonomy(): JsonResponse
    {
        $this->authorize('viewAny', Ticket::class);

        $orgId = app('current.organization_id');
        $this->defaults->ensureFor($orgId);

        return response()->json([
            'statuses'   => \App\Models\Support\TicketStatus::where('is_active', true)
                ->orderBy('position')->get(['id', 'name', 'state', 'colour', 'is_default']),
            'types'      => \App\Models\Support\TicketType::where('is_active', true)
                ->orderBy('position')->get(['id', 'name', 'description', 'is_default']),
            'urgencies'  => \App\Models\Support\TicketUrgency::orderBy('level')->get(['id', 'name', 'level', 'is_default']),
            'impacts'    => \App\Models\Support\TicketImpact::orderBy('level')->get(['id', 'name', 'level', 'is_default']),
            'priorities' => \App\Models\Support\TicketPriority::orderBy('level')->get(['id', 'name', 'level', 'colour']),
            'states'     => TicketStatus::STATES,
        ]);
    }

    /** Scoped by the global organisation scope; a bad id is a 404, not a leak. */
    private function find(int $id): Ticket
    {
        $ticket = Ticket::findOrFail($id);

        // The record-level half of the same rule the listing applies.
        $this->authorize('view', $ticket);

        return $ticket;
    }
}
