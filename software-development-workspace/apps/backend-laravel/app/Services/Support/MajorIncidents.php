<?php

namespace App\Services\Support;

use App\Models\Support\Ticket;
use App\Models\Support\TicketMessage;
use App\Models\Support\TicketStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One outage is not forty faults.
 *
 * A link goes down and forty people write in. The old module had no way to say
 * so, so forty tickets were worked, forty replies were typed, and forty
 * customers got told at forty different times — or, more often, thirty-nine
 * were closed with no reply at all.
 *
 * Joining them to a parent means one update reaches everybody, and the SLA on
 * each child is still measured on its own: each of those forty people was
 * promised something individually.
 */
class MajorIncidents
{
    public function __construct(private readonly TicketService $tickets) {}

    /**
     * Join a ticket to a parent.
     *
     * The child keeps its own clock. Merging is a statement about cause, not a
     * way to make thirty-nine breaches disappear.
     */
    public function merge(Ticket $child, Ticket $parent, ?User $actor = null): Ticket
    {
        if ($child->id === $parent->id) {
            throw new RuntimeException('A ticket cannot be its own parent.');
        }

        if ($child->organization_id !== $parent->organization_id) {
            throw new RuntimeException('Those tickets belong to different organisations.');
        }

        /*
         * One level only. A chain of parents means an update has to be walked
         * rather than fanned out, and nobody can answer "how many people are
         * affected?" without a recursive query.
         */
        if ($parent->parent_ticket_id !== null) {
            throw new RuntimeException(
                "{$parent->reference} is itself joined to {$parent->parent->reference}. "
                . 'Join this one to that instead.',
            );
        }

        if ($child->children()->exists()) {
            throw new RuntimeException(
                "{$child->reference} has tickets joined to it already, so it cannot become a child."
            );
        }

        return DB::transaction(function () use ($child, $parent, $actor) {
            $child->forceFill([
                'parent_ticket_id' => $parent->id,
                'merged_at'        => now(),
            ])->save();

            $this->tickets->reply($child, [
                'body'       => "Joined to {$parent->reference} — {$parent->subject}. "
                    . 'Updates on that ticket will be copied here.',
                'visibility' => TicketMessage::VISIBILITY_INTERNAL,
                'source'     => 'system',
            ], $actor);

            return $child->fresh(['parent']);
        });
    }

    /** Undo it. The child goes back to standing on its own. */
    public function detach(Ticket $child, ?User $actor = null): Ticket
    {
        $parent = $child->parent;

        $child->forceFill(['parent_ticket_id' => null, 'merged_at' => null])->save();

        if ($parent) {
            $this->tickets->reply($child, [
                'body'       => "Separated from {$parent->reference}.",
                'visibility' => TicketMessage::VISIBILITY_INTERNAL,
                'source'     => 'system',
            ], $actor);
        }

        return $child->fresh();
    }

    /**
     * Say it once, to everybody.
     *
     * The message is posted on the parent and copied to every child as a public
     * reply, so each customer is answered on their own ticket — which is what
     * they will look at, and what their SLA is measured on.
     *
     * @return array{parent: TicketMessage, children: int}
     */
    public function broadcast(Ticket $parent, string $body, ?User $actor = null): array
    {
        if ($parent->parent_ticket_id !== null) {
            throw new RuntimeException('Broadcast from the parent ticket, not a child.');
        }

        return DB::transaction(function () use ($parent, $body, $actor) {
            $message = $this->tickets->reply($parent, [
                'body'       => $body,
                'visibility' => TicketMessage::VISIBILITY_PUBLIC,
            ], $actor);

            $sent = 0;

            foreach ($parent->children()->get() as $child) {
                /*
                 * Each child gets it as a genuine agent reply, so it sets that
                 * ticket's first response if it has not been answered yet.
                 * Anything less means forty tickets breach while a single
                 * update sat on the parent.
                 */
                $this->tickets->reply($child, [
                    'body'          => $body,
                    'visibility'    => TicketMessage::VISIBILITY_PUBLIC,
                    'author_name'   => $actor?->name,
                    'author_email'  => $actor?->email,
                    'is_from_agent' => true,
                    'source'        => 'system',
                ], $actor);

                $sent++;
            }

            return ['parent' => $message, 'children' => $sent];
        });
    }

    /**
     * Resolve the parent and everything joined to it.
     *
     * Offered rather than automatic. Sometimes the outage is fixed and one
     * customer still has a separate problem underneath it, and closing theirs
     * because the link came back is how a real fault gets lost.
     */
    public function resolveAll(Ticket $parent, TicketStatus $status, ?User $actor = null): int
    {
        if (! $status->isTerminal()) {
            throw new RuntimeException('That status does not resolve anything.');
        }

        return DB::transaction(function () use ($parent, $status, $actor) {
            $this->tickets->changeStatus($parent, $status, $actor);

            $count = 1;

            foreach ($parent->children()->with('status')->get() as $child) {
                if ($child->status?->isTerminal()) {
                    continue;
                }

                $this->tickets->changeStatus($child, $status, $actor);
                $count++;
            }

            return $count;
        });
    }
}
