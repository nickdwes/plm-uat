<?php

namespace App\Console\Commands;

use App\Models\Support\SlaEvent;
use App\Models\Support\Ticket;
use App\Models\Support\TicketSla;
use App\Models\Support\TicketStatus;
use App\Services\NotificationService;
use App\Services\Support\SlaClock;
use Illuminate\Console\Command;

/**
 * Climb the ladder on tickets that have run past their thresholds.
 *
 * The old system had the three columns — HOD, CTO, CEO — and nothing that ever
 * wrote to them, because the targets they would have been measured against were
 * read from a column that does not exist. This is the engine that was missing.
 *
 * ── Driven by elapsed working time ──
 *
 * Not by "hours since created", which is what makes a Friday evening ticket
 * escalate to the CEO before Monday breakfast and teaches everybody to ignore
 * escalations. A paused ticket does not climb: waiting on a supplier is not the
 * desk failing.
 *
 * ── Each rung fires once ──
 *
 * The timestamp is the guard. An escalation that re-notifies every fifteen
 * minutes is an escalation people filter to a folder.
 */
class EscalateSupportTickets extends Command
{
    protected $signature = 'support:escalate {--dry-run : Report what would happen and change nothing}';

    protected $description = 'Escalate support tickets that have passed their SLA thresholds';

    public function handle(SlaClock $clock, NotificationService $notifications): int
    {
        $dry = (bool) $this->option('dry-run');

        $clocks = TicketSla::with(['ticket.status', 'ticket.assignee', 'target'])
            ->whereNull('paused_at')
            ->whereNull('resolution_met')
            ->whereHas('ticket.status', fn ($q) => $q->whereIn('state', [
                TicketStatus::STATE_OPEN,
            ]))
            ->get();

        $climbed = 0;

        foreach ($clocks as $record) {
            $ticket = $record->ticket;
            $target = $record->target;

            if (! $ticket || ! $target) {
                continue;
            }

            app()->instance('current.organization_id', $ticket->organization_id);

            $used = $clock->minutesUsed($ticket);
            $next = $this->nextRung($record, $target, $used);

            if (! $next) {
                continue;
            }

            [$level, $threshold] = $next;

            $this->line(sprintf(
                '%s %s → %s (%d working minutes, threshold %d)',
                $dry ? '[dry]' : '     ',
                $ticket->reference,
                strtoupper($level),
                $used,
                $threshold,
            ));

            if ($dry) {
                continue;
            }

            $record->forceFill([
                'escalation_level'       => $level,
                "{$level}_escalated_at"  => now(),
            ])->save();

            SlaEvent::create([
                'support_ticket_id' => $ticket->id,
                'event'             => SlaEvent::ESCALATED,
                'clock'             => 'resolution',
                'detail'            => sprintf(
                    'Escalated to %s at %d working minutes against a threshold of %d',
                    strtoupper($level), $used, $threshold,
                ),
                'working_minutes'   => $used,
                'occurred_at'       => now(),
            ]);

            $this->notify($notifications, $ticket, $level, $used);
            $climbed++;
        }

        $this->info($dry
            ? "{$clocks->count()} live tickets checked."
            : "{$climbed} escalated out of {$clocks->count()} live tickets.");

        return self::SUCCESS;
    }

    /**
     * The highest rung this ticket has passed and not yet been escalated to.
     *
     * Checked from the top down, so a ticket that has sat long enough to clear
     * two thresholds at once goes straight to the higher one rather than
     * climbing a rung per run and taking three hours to reach the CEO.
     */
    private function nextRung(TicketSla $record, $target, int $used): ?array
    {
        $rungs = [
            [TicketSla::LEVEL_CEO, $target->ceo_escalation_minutes, $record->ceo_escalated_at],
            [TicketSla::LEVEL_CTO, $target->cto_escalation_minutes, $record->cto_escalated_at],
            [TicketSla::LEVEL_HOD, $target->hod_escalation_minutes, $record->hod_escalated_at],
        ];

        foreach ($rungs as [$level, $threshold, $already]) {
            if ($threshold !== null && $already === null && $used >= $threshold) {
                return [$level, $threshold];
            }
        }

        return null;
    }

    /**
     * Tell somebody.
     *
     * The assignee is notified whatever the rung, because they are the person
     * who can still act on it. Notifying the CEO's inbox is a matter of who
     * holds the escalation role, which is configuration rather than something
     * to hard-code here.
     */
    private function notify(NotificationService $notifications, Ticket $ticket, string $level, int $used): void
    {
        if (! $ticket->assignee) {
            return;
        }

        try {
            $notifications->create(
                organizationId: (string) $ticket->organization_id,
                userId:         $ticket->assignee->id,
                type:           'support',
                title:          "{$ticket->reference} escalated to " . strtoupper($level),
                message:        "{$ticket->subject} — {$used} working minutes and still open.",
                data:           ['ticket_id' => $ticket->id, 'level' => $level],
            );
        } catch (\Throwable $e) {
            // An escalation that cannot be delivered is still an escalation
            // that happened; the record above is the part that matters.
            $this->warn("Could not notify on {$ticket->reference}: {$e->getMessage()}");
        }
    }
}
