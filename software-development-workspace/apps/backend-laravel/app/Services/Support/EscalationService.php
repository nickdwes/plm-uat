<?php

namespace App\Services\Support;

use App\Models\Support\Escalation;
use App\Models\Support\Team;
use App\Models\Support\Ticket;
use App\Models\Support\TicketEvent;
use App\Models\Support\TicketMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Handing a ticket to different expertise, deliberately.
 *
 * Not the SLA ladder. That fires on a clock and says a target is at risk; this
 * is a judgement, and it carries a reason somebody typed.
 *
 * ── The clock does not stop ──
 *
 * Escalating is not an excuse. The customer was promised a resolution and does
 * not care how many people it passed through, so the SLA clock runs exactly as
 * before — the only thing that pauses it is waiting on somebody outside the
 * business, which is what the pending states are for.
 *
 * ── Ownership moves, history does not ──
 *
 * The assignee changes and every previous holder stays on the record. "Who had
 * this before me and what did they try" is the first question a specialist
 * asks, and it has to be answerable from the ticket rather than from memory.
 */
class EscalationService
{
    public function __construct(private readonly TicketService $tickets) {}

    /**
     * @param  array $data level, reason, to_user_id, to_team_id
     */
    public function escalate(Ticket $ticket, array $data, ?User $actor = null): Escalation
    {
        if (blank($data['reason'] ?? null)) {
            throw new RuntimeException(
                'An escalation needs a reason. The person picking it up has to know what has '
                . 'already been tried, and "escalated" on its own tells them nothing.',
            );
        }

        if (empty($data['to_user_id']) && empty($data['to_team_id'])) {
            throw new RuntimeException('Escalate to somebody or to a team — otherwise nothing has moved.');
        }

        return DB::transaction(function () use ($ticket, $data, $actor) {
            $fromUser = $ticket->assigned_to;
            $fromTeam = $ticket->support_team_id;

            $escalation = Escalation::create([
                'support_ticket_id' => $ticket->id,
                'level'        => $data['level'],
                'from_user_id' => $fromUser,
                'to_user_id'   => $data['to_user_id'] ?? null,
                'from_team_id' => $fromTeam,
                'to_team_id'   => $data['to_team_id'] ?? null,
                'reason'       => $data['reason'],
                'escalated_by' => $actor?->id,
                'escalated_at' => now(),
            ]);

            $ticket->forceFill([
                'assigned_to'         => $data['to_user_id'] ?? null,
                'support_team_id'     => $data['to_team_id'] ?? $ticket->support_team_id,
                // Who the escalation sits with, kept apart from who owns the fix.
                'escalation_owner_id' => $data['to_user_id'] ?? null,
            ])->save();

            $this->record($ticket, TicketEvent::ESCALATED, $fromUser, $data, $actor);

            /*
             * On the ticket as an internal note, not just in an audit table.
             * The next person to open this reads the thread, and a hand-off
             * they have to go looking for is a hand-off they will miss.
             */
            $this->tickets->reply($ticket, [
                'body' => sprintf(
                    "Escalated to %s.\n\nReason: %s",
                    Escalation::LEVELS[$data['level']] ?? $data['level'],
                    $data['reason'],
                ),
                'visibility' => TicketMessage::VISIBILITY_INTERNAL,
                'source'     => 'system',
            ], $actor);

            return $escalation;
        });
    }

    /**
     * Hand it back, with what was found.
     *
     * The outcome is required for the same reason the escalation reason was: a
     * specialist returning a ticket with nothing written down has moved the
     * work without moving the knowledge.
     */
    public function resolve(Escalation $escalation, string $outcome, ?User $actor = null): Escalation
    {
        if (blank($outcome)) {
            throw new RuntimeException('Say what was found before handing it back.');
        }

        return DB::transaction(function () use ($escalation, $outcome, $actor) {
            $escalation->forceFill([
                'resolved_at' => now(),
                'outcome'     => $outcome,
                'resolved_by' => $actor?->id,
            ])->save();

            $ticket = $escalation->ticket;

            // Back to whoever held it, and the escalation owner is cleared.
            $ticket->forceFill([
                'assigned_to'         => $escalation->from_user_id ?? $ticket->assigned_to,
                'support_team_id'     => $escalation->from_team_id ?? $ticket->support_team_id,
                'escalation_owner_id' => null,
            ])->save();

            TicketEvent::create([
                'support_ticket_id' => $ticket->id,
                'event'      => TicketEvent::DEESCALATED,
                'field'      => 'escalation',
                'to_value'   => $escalation->from_user_id ? "user {$escalation->from_user_id}" : null,
                'reason'     => $outcome,
                'actor_id'   => $actor?->id,
                'occurred_at' => now(),
            ]);

            $this->tickets->reply($ticket, [
                'body'       => "Returned from escalation.\n\nOutcome: {$outcome}",
                'visibility' => TicketMessage::VISIBILITY_INTERNAL,
                'source'     => 'system',
            ], $actor);

            return $escalation->fresh();
        });
    }

    /**
     * Move a ticket to somebody else, with a reason.
     *
     * Separate from escalation: a reassignment says the wrong person has it, an
     * escalation says the right person needs help. Recorded either way, because
     * "who sent this to me" cannot be answered from a column holding only the
     * current value.
     */
    public function reassign(Ticket $ticket, ?int $userId, ?int $teamId, string $reason, ?User $actor = null): Ticket
    {
        $fromUser = $ticket->assigned_to;
        $fromTeam = $ticket->support_team_id;

        $ticket->forceFill([
            'assigned_to'     => $userId,
            'support_team_id' => $teamId ?? $ticket->support_team_id,
        ])->save();

        TicketEvent::create([
            'support_ticket_id' => $ticket->id,
            'event'      => $fromUser ? TicketEvent::REASSIGNED : TicketEvent::ASSIGNED,
            'field'      => 'assigned_to',
            'from_value' => $fromUser ? "user {$fromUser}" : ($fromTeam ? "team {$fromTeam}" : null),
            'to_value'   => $userId ? "user {$userId}" : ($teamId ? "team {$teamId}" : 'unassigned'),
            'reason'     => $reason,
            'actor_id'   => $actor?->id,
            'occurred_at' => now(),
        ]);

        return $ticket->fresh(['assignee', 'team']);
    }

    private function record(Ticket $ticket, string $event, ?int $fromUser, array $data, ?User $actor): void
    {
        $to = $data['to_user_id']
            ? "user {$data['to_user_id']}"
            : 'team ' . ($data['to_team_id'] ?? '');

        TicketEvent::create([
            'support_ticket_id' => $ticket->id,
            'event'      => $event,
            'field'      => 'escalation',
            'from_value' => $fromUser ? "user {$fromUser}" : null,
            'to_value'   => $to,
            'reason'     => $data['reason'],
            'actor_id'   => $actor?->id,
            'occurred_at' => now(),
        ]);
    }
}
