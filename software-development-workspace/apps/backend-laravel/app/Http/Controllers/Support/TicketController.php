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
        $query = Ticket::with(['status', 'type', 'priority', 'assignee', 'account', 'sla'])
            ->withCount('messages');

        // Filters, all optional, all narrowing rather than replacing.
        if ($request->filled('status'))   { $query->where('ticket_status_id', $request->integer('status')); }
        if ($request->filled('state'))    {
            $query->whereHas('status', fn ($q) => $q->where('state', $request->string('state')));
        }
        if ($request->filled('assignee')) { $query->where('assigned_to', $request->integer('assignee')); }
        if ($request->filled('account'))  { $query->where('account_id', $request->integer('account')); }
        if ($request->filled('type'))     { $query->where('ticket_type_id', $request->integer('type')); }

        if ($request->boolean('mine') && $request->user()) {
            $query->where('assigned_to', $request->user()->id);
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

        $data = $request->validate([
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $record = $this->find($ticket);
        $record->update(['assigned_to' => $data['assigned_to'] ?? null]);

        return response()->json(['data' => $record->fresh(['assignee'])]);
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
        return Ticket::findOrFail($id);
    }
}
