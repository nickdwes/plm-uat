<?php

namespace App\Services\Support;

use App\Models\Support\PriorityMatrix;
use App\Models\Support\Ticket;
use App\Models\Support\TicketMessage;
use App\Models\Support\TicketStatus;
use App\Models\Support\TicketWorklog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The rules a ticket obeys, in one place.
 *
 * Everything that writes a ticket goes through here — the agent screen now, the
 * mail ingestion next phase, the portal after that. Three doors onto one record
 * is exactly how a first-response timestamp ends up meaning something different
 * depending on which door was used.
 */
class TicketService
{
    public function __construct(
        private readonly SupportDefaults $defaults = new SupportDefaults(),
        private readonly ?SlaClock $clock = null,
    ) {}

    /**
     * Resolved lazily rather than injected, because the clock reads tickets and
     * the service writes them — constructing each from the other's constructor
     * is a cycle the container cannot settle.
     */
    private function clock(): SlaClock
    {
        return $this->clock ?? app(SlaClock::class);
    }

    /**
     * Open a ticket.
     *
     * @param  array $data subject, body, requester_*, account_id, type/urgency/impact ids, source
     */
    public function open(int $organizationId, array $data, ?User $actor = null): Ticket
    {
        $this->defaults->ensureFor($organizationId);

        return DB::transaction(function () use ($organizationId, $data, $actor) {
            $status = $this->defaultStatus($organizationId);

            $ticket = Ticket::create([
                'organization_id' => $organizationId,
                'reference'       => Ticket::nextReference($organizationId),
                'subject'         => $data['subject'],
                'body'            => $data['body'] ?? null,

                'account_id'          => $data['account_id'] ?? null,
                'account_contract_id' => $data['account_contract_id'] ?? null,
                'account_contact_id'  => $data['account_contact_id'] ?? null,

                'requester_name'    => $data['requester_name'] ?? null,
                'requester_email'   => $data['requester_email'] ?? null,
                'requester_user_id' => $data['requester_user_id'] ?? null,

                'ticket_type_id'   => $data['ticket_type_id'] ?? null,
                'ticket_status_id' => $status->id,
                'urgency_id'       => $data['urgency_id'] ?? null,
                'impact_id'        => $data['impact_id'] ?? null,
                // Never taken from the caller: it is a lookup, not an opinion.
                'priority_id'      => $this->priorityFor($organizationId, $data),

                'assigned_to'      => $data['assigned_to'] ?? null,
                'parent_ticket_id' => $data['parent_ticket_id'] ?? null,
                'source'           => $data['source'] ?? 'agent',
                'opened_at'        => now(),
                'created_by'       => $actor?->id,
            ]);

            /*
             * The opening description is the first message, not just a column.
             * Otherwise the conversation on screen starts halfway through, with
             * the reply to something nobody can see.
             */
            if (filled($data['body'] ?? null)) {
                $ticket->messages()->create([
                    'visibility'     => TicketMessage::VISIBILITY_PUBLIC,
                    'body'           => $data['body'],
                    'author_user_id' => $data['requester_user_id'] ?? null,
                    'author_name'    => $data['requester_name'] ?? null,
                    'author_email'   => $data['requester_email'] ?? null,
                    'source'         => $data['source'] ?? 'agent',
                    'is_from_agent'  => false,
                ]);
            }

            /*
             * A target only exists once there is a priority, which an emailed
             * ticket does not have until somebody triages it. Attaching returns
             * null in that case rather than starting a countdown against a
             * promise nobody made.
             */
            $this->clock()->attach($ticket);

            return $ticket->fresh(['status', 'priority', 'sla']);
        });
    }

    /**
     * Add a message, and let it set the first-response mark if it is one.
     *
     * The old module had a field an agent set by pressing a button, which
     * measured the button. A first response is the first public reply from an
     * agent, so that is what records it — and it cannot be set twice.
     */
    public function reply(Ticket $ticket, array $data, ?User $actor = null): TicketMessage
    {
        return DB::transaction(function () use ($ticket, $data, $actor) {
            $fromAgent = (bool) ($data['is_from_agent'] ?? ($actor !== null));

            $message = $ticket->messages()->create([
                'visibility'     => $data['visibility'] ?? TicketMessage::VISIBILITY_PUBLIC,
                'body'           => $data['body'],
                'author_user_id' => $actor?->id ?? ($data['author_user_id'] ?? null),
                'author_name'    => $data['author_name'] ?? $actor?->name,
                'author_email'   => $data['author_email'] ?? $actor?->email,
                'source'         => $data['source'] ?? 'agent',
                'is_from_agent'  => $fromAgent,
            ]);

            if ($message->countsAsFirstResponse() && $ticket->first_response_at === null) {
                $ticket->forceFill(['first_response_at' => $message->created_at])->save();

                // The verdict is written now and never recalculated.
                $this->clock()->onFirstResponse($ticket->fresh(), $message->created_at);
            }

            return $message;
        });
    }

    /**
     * Move a ticket to another status, keeping its timestamps honest.
     *
     * Reaching a resolved or closed state stamps the time; leaving one clears
     * it, because a resolved_at on a ticket that is open again is a false fact
     * — and it is the fact every resolution-time report would be built on.
     */
    public function changeStatus(Ticket $ticket, TicketStatus $status, ?User $actor = null): Ticket
    {
        if ($status->organization_id !== $ticket->organization_id) {
            throw new RuntimeException('That status belongs to another organisation.');
        }

        $changes = ['ticket_status_id' => $status->id];

        $changes['resolved_at'] = match ($status->state) {
            TicketStatus::STATE_RESOLVED, TicketStatus::STATE_CLOSED => $ticket->resolved_at ?? now(),
            default => null,
        };

        $changes['closed_at'] = $status->state === TicketStatus::STATE_CLOSED
            ? ($ticket->closed_at ?? now())
            : null;

        $ticket->forceFill($changes)->save();

        $fresh = $ticket->fresh(['status', 'sla']);

        /*
         * Pause and resume follow the state, and reaching a terminal state
         * settles the resolution clock. Both go through the clock rather than
         * being written here, so the mail ingestion and the portal get the same
         * behaviour without repeating it.
         */
        $this->clock()->onStatusChanged($fresh, $status);

        if ($status->isTerminal() && $fresh->resolved_at) {
            $this->clock()->onResolved($fresh, $fresh->resolved_at);
        }

        return $fresh;
    }

    /** Reclassify, and let the priority follow rather than be argued about. */
    public function reclassify(Ticket $ticket, array $data): Ticket
    {
        $ticket->fill(array_filter([
            'ticket_type_id' => $data['ticket_type_id'] ?? null,
            'urgency_id'     => $data['urgency_id'] ?? null,
            'impact_id'      => $data['impact_id'] ?? null,
            'account_id'     => $data['account_id'] ?? null,
        ], fn ($v) => $v !== null));

        $ticket->priority_id = $this->priorityFor($ticket->organization_id, [
            'impact_id'  => $ticket->impact_id,
            'urgency_id' => $ticket->urgency_id,
        ]);

        $ticket->save();

        /*
         * Triage is where most emailed tickets first get a priority, and so
         * where most clocks actually start. Re-attaching leaves an already-run
         * clock alone — see SlaClock::attach.
         */
        $this->clock()->attach($ticket->fresh());

        return $ticket->fresh(['priority', 'type', 'urgency', 'impact', 'sla']);
    }

    public function logTime(Ticket $ticket, User $user, array $data): TicketWorklog
    {
        return $ticket->worklogs()->create([
            'user_id'        => $user->id,
            'minutes'        => (int) $data['minutes'],
            'performed_on'   => $data['performed_on'] ?? now()->toDateString(),
            'note'           => $data['note'] ?? null,
            'under_contract' => (bool) ($data['under_contract'] ?? true),
        ]);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function priorityFor(int $organizationId, array $data): ?int
    {
        return PriorityMatrix::resolve(
            $organizationId,
            $data['impact_id'] ?? null,
            $data['urgency_id'] ?? null,
        )?->id;
    }

    /**
     * The status a new ticket starts in.
     *
     * Falls back to the lowest-positioned open status rather than failing: an
     * organisation that has renamed or deleted its default must still be able
     * to take a call.
     */
    private function defaultStatus(int $organizationId): TicketStatus
    {
        $query = fn () => TicketStatus::withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->where('is_active', true);

        return $query()->where('is_default', true)->first()
            ?? $query()->where('state', TicketStatus::STATE_OPEN)->orderBy('position')->firstOrFail();
    }
}
