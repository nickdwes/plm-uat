<?php

namespace App\Services\Support;

use App\Models\Support\Automation;
use App\Models\Support\AutomationRun;
use App\Models\Support\Ticket;
use App\Models\Support\TicketMessage;
use App\Models\Support\TicketStatus;
use Illuminate\Support\Facades\DB;

/**
 * Applying the rules, and writing down what they did.
 *
 * ── The recursion guard ──
 *
 * Every action here changes a ticket, and changing a ticket is what fires an
 * `updated` rule. Without a guard, a rule that sets a priority triggers itself,
 * or triggers a second rule that triggers the first — and the first sign is a
 * queue where every ticket has been touched four hundred times. The flag is
 * held for the duration of a pass rather than per rule, because two rules
 * bouncing off each other is the same failure as one.
 *
 * ── Conditions are deliberately few ──
 *
 * Status, state, priority, category, type, account, sender domain, keyword, and
 * how long something has sat. That covers the triage a desk actually does. A
 * general expression language would cover more and would produce rules nobody
 * can read at eight in the morning when one of them has done something odd.
 */
class AutomationEngine
{
    /** True while a pass is applying actions, so those changes fire nothing. */
    private static bool $applying = false;

    public function __construct(private readonly TicketService $tickets) {}

    /** Run the rules for an event. Returns the runs recorded. */
    public function run(Ticket $ticket, string $trigger): array
    {
        if (self::$applying) {
            return [];
        }

        $rules = Automation::withoutGlobalScope('organization')
            ->where('organization_id', $ticket->organization_id)
            ->where('trigger', $trigger)
            ->where('is_active', true)
            ->orderBy('position')->orderBy('id')
            ->get();

        $recorded = [];

        self::$applying = true;

        try {
            foreach ($rules as $rule) {
                if (! $this->matches($rule, $ticket->fresh(['status', 'priority', 'serviceCategory']))) {
                    continue;
                }

                if (! $rule->mayActOn($ticket)) {
                    continue;
                }

                $recorded[] = $this->apply($rule, $ticket->fresh());
            }
        } finally {
            self::$applying = false;
        }

        return array_filter($recorded);
    }

    /** The scheduled pass, over tickets that are still live. */
    public function runScheduled(int $organizationId): array
    {
        $rules = Automation::withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->where('trigger', Automation::ON_SCHEDULE)
            ->where('is_active', true)
            ->orderBy('position')->orderBy('id')
            ->get();

        if ($rules->isEmpty()) {
            return [];
        }

        /*
         * Only open and pending tickets. A scheduled rule sweeping closed work
         * is a rule that reopens last year, and the cost of the sweep is
         * proportional to how much history there is rather than how much work
         * there is.
         */
        $tickets = Ticket::withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->whereHas('status', fn ($q) => $q->whereIn('state', [
                TicketStatus::STATE_OPEN, TicketStatus::STATE_PENDING,
            ]))
            ->with(['status', 'priority', 'serviceCategory'])
            ->limit(2000)
            ->get();

        $recorded = [];

        self::$applying = true;

        try {
            foreach ($rules as $rule) {
                foreach ($tickets as $ticket) {
                    if (! $this->matches($rule, $ticket) || ! $rule->mayActOn($ticket)) {
                        continue;
                    }

                    $recorded[] = $this->apply($rule, $ticket->fresh());
                }
            }
        } finally {
            self::$applying = false;
        }

        return array_filter($recorded);
    }

    // ── Conditions ────────────────────────────────────────────────────────────

    /** Every condition that is set must hold; unset ones are ignored. */
    public function matches(Automation $rule, Ticket $ticket): bool
    {
        foreach ($rule->conditions ?? [] as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (! $this->holds($key, $value, $ticket)) {
                return false;
            }
        }

        return true;
    }

    private function holds(string $key, mixed $value, Ticket $ticket): bool
    {
        return match ($key) {
            'state'        => $ticket->status?->state === $value,
            'status_id'    => (int) $ticket->ticket_status_id === (int) $value,
            'priority_id'  => (int) $ticket->priority_id === (int) $value,
            'type_id'      => (int) $ticket->ticket_type_id === (int) $value,
            'category_id'  => (int) $ticket->service_category_id === (int) $value,
            'account_id'   => (int) $ticket->account_id === (int) $value,
            'source'       => $ticket->source === $value,

            'unassigned'   => $value ? $ticket->assigned_to === null : $ticket->assigned_to !== null,

            'sender_domain' => str_ends_with(
                mb_strtolower((string) $ticket->requester_email), '@' . mb_strtolower((string) $value),
            ),

            'keyword' => str_contains(
                mb_strtolower($ticket->subject . ' ' . (string) $ticket->body), mb_strtolower((string) $value),
            ),

            /*
             * How long since anybody said anything, which is the question a
             * chase rule is actually asking — not how old the ticket is. A
             * ticket opened three weeks ago and answered this morning is not
             * stale.
             */
            'silent_hours' => $this->hoursSinceLastMessage($ticket) >= (int) $value,

            'age_hours'    => $ticket->opened_at !== null
                && $ticket->opened_at->diffInHours(now()) >= (int) $value,

            default => true,
        };
    }

    private function hoursSinceLastMessage(Ticket $ticket): int
    {
        $last = $ticket->messages()->orderByDesc('id')->value('created_at');

        return $last ? (int) \Carbon\Carbon::parse($last)->diffInHours(now()) : 0;
    }

    // ── Actions ───────────────────────────────────────────────────────────────

    private function apply(Automation $rule, Ticket $ticket): ?AutomationRun
    {
        $did = [];

        foreach ($rule->actions ?? [] as $action => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $described = $rule->is_dry_run
                ? $this->describe($action, $value, $ticket)
                : $this->perform($action, $value, $ticket);

            if ($described) {
                $did[$action] = $described;
            }
        }

        if ($did === []) {
            return null;
        }

        return DB::transaction(function () use ($rule, $ticket, $did) {
            if (! $rule->is_dry_run) {
                $rule->forceFill([
                    'fired_count'   => $rule->fired_count + 1,
                    'last_fired_at' => now(),
                ])->save();
            }

            return AutomationRun::create([
                'support_automation_id' => $rule->id,
                'support_ticket_id'     => $ticket->id,
                'was_dry_run'           => $rule->is_dry_run,
                'summary'               => implode('; ', array_values($did)),
                'actions'               => $did,
                'fired_at'              => now(),
            ]);
        });
    }

    /** What it would do, for a rehearsal. */
    private function describe(string $action, mixed $value, Ticket $ticket): ?string
    {
        return match ($action) {
            'set_status'   => 'Would set the status to ' . ($this->status($ticket, $value)?->name ?? '?'),
            'set_priority' => 'Would set the priority',
            'assign_to'    => 'Would assign it',
            'set_team'     => 'Would move it to another queue',
            'add_note'     => 'Would add an internal note',
            'reply'        => 'Would reply to the customer',
            default        => null,
        };
    }

    private function perform(string $action, mixed $value, Ticket $ticket): ?string
    {
        switch ($action) {
            case 'set_status':
                $status = $this->status($ticket, $value);

                if (! $status) {
                    return null;
                }

                $this->tickets->changeStatus($ticket, $status);

                return "Set the status to {$status->name}";

            case 'set_priority':
                $ticket->forceFill(['priority_id' => (int) $value])->save();

                return 'Set the priority';

            case 'assign_to':
                $ticket->forceFill(['assigned_to' => (int) $value])->save();

                return 'Assigned it';

            case 'set_team':
                $ticket->forceFill(['support_team_id' => (int) $value])->save();

                return 'Moved it to another queue';

            case 'add_note':
                $this->tickets->reply($ticket, [
                    'body'       => (string) $value,
                    'visibility' => TicketMessage::VISIBILITY_INTERNAL,
                    'source'     => 'system',
                ]);

                return 'Added an internal note';

            case 'reply':
                /*
                 * Sent as the desk, and never counted as a first response. An
                 * automated chase is not somebody answering, and letting it
                 * stop the response clock would make every SLA figure a
                 * measure of how fast the robot is.
                 */
                $this->tickets->reply($ticket, [
                    'body'          => (string) $value,
                    'visibility'    => TicketMessage::VISIBILITY_PUBLIC,
                    'source'        => 'system',
                    'is_from_agent' => false,
                ]);

                return 'Replied to the customer';
        }

        return null;
    }

    private function status(Ticket $ticket, mixed $id): ?TicketStatus
    {
        return TicketStatus::withoutGlobalScope('organization')
            ->where('organization_id', $ticket->organization_id)
            ->find((int) $id);
    }
}
