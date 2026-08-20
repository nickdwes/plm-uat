<?php

namespace App\Services\Support;

use App\Models\Support\Automation;
use App\Models\Support\PriorityMatrix;
use App\Models\Support\Ticket;
use App\Models\Support\TicketEvent;
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

                'ticket_type_id'      => $data['ticket_type_id'] ?? null,
                'service_category_id' => $data['service_category_id'] ?? null,
                'ticket_status_id' => $status->id,
                'urgency_id'       => $data['urgency_id'] ?? null,
                'impact_id'        => $data['impact_id'] ?? null,
                // Never taken from the caller: it is a lookup, not an opinion.
                'priority_id'      => $this->priorityFor($organizationId, $data),

                'assigned_to'      => $data['assigned_to'] ?? null,
                'support_team_id'  => $data['support_team_id'] ?? null,
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

            /*
             * Routed after the clock, because a rule can match on priority and
             * the priority is settled by then. Routing goes to the engineer who
             * knows the customer rather than to whoever is free — see
             * RoutingEngine. It never overrides an assignment the caller made.
             */
            app(RoutingEngine::class)->route($ticket);

            /*
             * Automation runs last, after routing and the clock, so a rule can
             * see the queue and the priority the ticket actually ended up with
             * rather than the blank it was created with.
             */
            app(AutomationEngine::class)->run($ticket->fresh(), Automation::ON_CREATED);

            return $ticket->fresh(['status', 'priority', 'sla', 'team', 'assignee']);
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

        $was = $ticket->status;

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

        $this->recordStatusChange($fresh, $was, $status, $actor);

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

        // The engine ignores this while it is applying its own actions.
        app(AutomationEngine::class)->run($fresh, Automation::ON_UPDATED);

        return $fresh->fresh(['status']);
    }

    /**
     * Write the status move down, and mark the two crossings that matter.
     *
     * The trail exists because a ticket's columns only say where it is now:
     * "who closed this, and when" had no answer, and reopening one erased the
     * resolution date the reports were counted from — so last month's figures
     * changed after the fact. A resolved event is recorded on every entry into
     * a terminal state and a reopened event on every departure from one; both
     * are written once and never revised, which is what makes a report run in
     * October say the same thing about July as it did in August.
     */
    private function recordStatusChange(
        Ticket $ticket,
        ?TicketStatus $was,
        TicketStatus $now,
        ?User $actor,
    ): void {
        if ($was?->id === $now->id) {
            return;
        }

        $common = [
            'support_ticket_id' => $ticket->id,
            'actor_id'          => $actor?->id,
            'automatic'         => $actor === null,
            'occurred_at'       => now(),
        ];

        TicketEvent::create($common + [
            'event'      => TicketEvent::STATUS,
            'field'      => 'status',
            'from_value' => $was?->name,
            'to_value'   => $now->name,
        ]);

        $wasTerminal = $was?->isTerminal() ?? false;

        if ($now->isTerminal() && ! $wasTerminal) {
            TicketEvent::create($common + [
                'event'    => TicketEvent::RESOLVED,
                'to_value' => $now->name,
            ]);
        }

        if ($wasTerminal && ! $now->isTerminal()) {
            TicketEvent::create($common + [
                'event'      => TicketEvent::REOPENED,
                'from_value' => $was?->name,
                'to_value'   => $now->name,
            ]);
        }
    }

    /** Reclassify, and let the priority follow rather than be argued about. */
    public function reclassify(Ticket $ticket, array $data): Ticket
    {
        $ticket->fill(array_filter([
            'ticket_type_id'      => $data['ticket_type_id'] ?? null,
            'service_category_id' => $data['service_category_id'] ?? null,
            'urgency_id'          => $data['urgency_id'] ?? null,
            'impact_id'           => $data['impact_id'] ?? null,
            'account_id'          => $data['account_id'] ?? null,
            'account_contract_id' => $data['account_contract_id'] ?? null,
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

        /*
         * Re-routed after triage, because the service category is usually set
         * here and it is what decides which engineer on the contract takes it.
         * A ticket somebody has already picked up is left alone.
         */
        app(RoutingEngine::class)->route($ticket->fresh());

        return $ticket->fresh(['priority', 'type', 'urgency', 'impact', 'sla', 'team', 'assignee']);
    }

    /**
     * Take part of a conversation and make it its own ticket.
     *
     * "The laptop is slow, and while I have you, we need a mailbox for the new
     * starter" is two pieces of work with two owners and two clocks. Worked as
     * one ticket it closes when the slower half finishes, which is how a desk
     * ends up with resolution times nobody recognises.
     *
     * ── The chosen messages are copied, not moved ──
     *
     * The original keeps its whole history. Moving messages out would leave a
     * thread with replies to something nobody can see, and the customer's own
     * copy of that conversation still has them — so the ticket would no longer
     * match what they are reading.
     *
     * @param  array $data subject, message_ids, ticket_type_id, service_category_id
     */
    public function split(Ticket $ticket, array $data, ?User $actor = null): Ticket
    {
        if (blank($data['subject'] ?? null)) {
            throw new RuntimeException('The new ticket needs a subject of its own.');
        }

        $messages = $ticket->messages()
            ->whereIn('id', $data['message_ids'] ?? [])
            ->orderBy('id')
            ->get();

        return DB::transaction(function () use ($ticket, $data, $messages, $actor) {
            /*
             * The customer, account and contract come across unchanged: it is
             * the same person asking, so the same service level applies and the
             * same engineers are the right ones to consider.
             */
            $fresh = $this->open($ticket->organization_id, [
                'subject'             => $data['subject'],
                'body'                => $messages->first()?->body ?? $ticket->body,
                'account_id'          => $ticket->account_id,
                'account_contract_id' => $ticket->account_contract_id,
                'account_contact_id'  => $ticket->account_contact_id,
                'requester_name'      => $ticket->requester_name,
                'requester_email'     => $ticket->requester_email,
                'requester_user_id'   => $ticket->requester_user_id,
                'ticket_type_id'      => $data['ticket_type_id'] ?? $ticket->ticket_type_id,
                // Usually the point of splitting: the second half is a
                // different discipline and belongs to different people.
                'service_category_id' => $data['service_category_id'] ?? null,
                'source'              => $ticket->source,
            ], $actor);

            $fresh->forceFill(['split_from_ticket_id' => $ticket->id])->save();

            /*
             * Everything after the first, copied in order. The first is already
             * the opening description, so copying it again would show it twice.
             */
            foreach ($messages->skip(1) as $message) {
                $fresh->messages()->create([
                    'visibility'     => $message->visibility,
                    'body'           => $message->body,
                    'author_user_id' => $message->author_user_id,
                    'author_name'    => $message->author_name,
                    'author_email'   => $message->author_email,
                    'source'         => $message->source,
                    'is_from_agent'  => $message->is_from_agent,
                ]);
            }

            // Both sides say where the other is, because the question asked
            // later is always "where did the rest of this go?".
            $this->reply($ticket, [
                'body'       => "Split: {$fresh->reference} raised for \"{$data['subject']}\".",
                'visibility' => TicketMessage::VISIBILITY_INTERNAL,
                'source'     => 'system',
            ], $actor);

            $this->reply($fresh, [
                'body'       => "Split out of {$ticket->reference} — {$ticket->subject}.",
                'visibility' => TicketMessage::VISIBILITY_INTERNAL,
                'source'     => 'system',
            ], $actor);

            TicketEvent::create([
                'support_ticket_id' => $ticket->id,
                'event'      => 'split',
                'field'      => 'ticket',
                'to_value'   => $fresh->reference,
                'reason'     => $data['subject'],
                'actor_id'   => $actor?->id,
                'occurred_at' => now(),
            ]);

            return $fresh->fresh(['status', 'priority', 'team', 'assignee', 'sla']);
        });
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
