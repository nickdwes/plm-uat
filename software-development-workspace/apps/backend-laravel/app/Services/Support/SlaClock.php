<?php

namespace App\Services\Support;

use App\Models\Support\Calendar;
use App\Models\Support\Sla;
use App\Models\Support\SlaEvent;
use App\Models\Support\Ticket;
use App\Models\Support\TicketSla;
use App\Models\Support\TicketStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The service-level clock.
 *
 * ── What the old one did ──
 *
 * It read response and resolution targets off the urgency record. That table
 * has five columns and neither is one of them, so every SLA figure was computed
 * from null and skipped. Where arithmetic did run, it asked
 * `if (allowed <= elapsed) { on_time++ }` — which counts every breach as a
 * success — and incremented the late counter inside the success branch.
 *
 * None of that is ported. This is written from the calendar up.
 *
 * ── Met is decided once ──
 *
 * The moment a response or a resolution lands, the verdict is written and never
 * recalculated. A figure that can be re-derived differently later — after the
 * priority is changed, after the contract is renegotiated — is a figure both
 * sides can dispute, and the whole point of a service review is that they
 * cannot.
 */
class SlaClock
{
    /**
     * Attach a clock to a ticket, or restate it after a reclassification.
     *
     * Nothing happens without a priority: an untriaged ticket has no target,
     * and inventing one would start a countdown against a promise nobody made.
     */
    public function attach(Ticket $ticket): ?TicketSla
    {
        $sla = $this->slaFor($ticket);

        if (! $sla) {
            return null;
        }

        $target = $sla->targetFor($ticket->priority_id);

        if (! $target) {
            return null;
        }

        $calendar = $this->calendarFor($sla, $ticket->organization_id);
        $start    = $ticket->opened_at ?? $ticket->created_at ?? now();

        return DB::transaction(function () use ($ticket, $sla, $target, $calendar, $start) {
            $clock = TicketSla::firstOrNew(['support_ticket_id' => $ticket->id]);

            /*
             * A clock that has already run is not restated. Somebody
             * reclassifying a ticket after it was answered must not move the
             * goalposts the answer was judged against.
             */
            if ($clock->exists && ($clock->response_met !== null || $clock->resolution_met !== null)) {
                return $clock;
            }

            $clock->fill([
                'support_sla_id'        => $sla->id,
                'support_sla_target_id' => $target->id,
                'response_minutes'      => $target->response_minutes,
                'resolution_minutes'    => $target->resolution_minutes,
                'response_due_at'       => $calendar->addWorkingMinutes($start, $target->response_minutes),
                'resolution_due_at'     => $calendar->addWorkingMinutes($start, $target->resolution_minutes),
                'clock_started_at'      => $clock->clock_started_at ?? $start,
            ])->save();

            if ($clock->wasRecentlyCreated) {
                $this->record($ticket, SlaEvent::STARTED, null,
                    "Target set from {$sla->name}", $start);
            }

            return $clock;
        });
    }

    /**
     * Follow a status change.
     *
     * `pending` stops both clocks and records why. Anything else resumes them,
     * banking the paused time. A ticket parked with a supplier for a fortnight
     * is not a fortnight of our failure — and the recorded pause is what makes
     * the gap between elapsed and counted explainable rather than arguable.
     */
    public function onStatusChanged(Ticket $ticket, TicketStatus $status): void
    {
        $clock = $ticket->sla;

        if (! $clock) {
            return;
        }

        $shouldPause = $status->state === TicketStatus::STATE_PENDING;

        if ($shouldPause && ! $clock->isPaused()) {
            $clock->forceFill(['paused_at' => now()])->save();
            $this->record($ticket, SlaEvent::PAUSED, null, "Status changed to {$status->name}");

            return;
        }

        if (! $shouldPause && $clock->isPaused()) {
            $calendar = $this->calendarFor($clock->sla, $ticket->organization_id);
            $paused   = $calendar->workingMinutesBetween($clock->paused_at, now());

            /*
             * The due dates move by the working time lost, so a countdown drawn
             * from them stays true without every reader recomputing the pause.
             */
            $clock->forceFill([
                'paused_at'         => null,
                'paused_minutes'    => $clock->paused_minutes + $paused,
                'response_due_at'   => $clock->response_met === null && $clock->response_due_at
                    ? $calendar->addWorkingMinutes($clock->response_due_at, $paused)
                    : $clock->response_due_at,
                'resolution_due_at' => $clock->resolution_due_at
                    ? $calendar->addWorkingMinutes($clock->resolution_due_at, $paused)
                    : null,
            ])->save();

            $this->record($ticket, SlaEvent::RESUMED, null,
                "Status changed to {$status->name}", null, $paused);
        }
    }

    /** The first public agent reply. Decided once, then never revisited. */
    public function onFirstResponse(Ticket $ticket, CarbonInterface $at): void
    {
        $clock = $ticket->sla;

        if (! $clock || $clock->response_met !== null) {
            return;
        }

        $calendar = $this->calendarFor($clock->sla, $ticket->organization_id);
        $used     = $calendar->workingMinutesBetween($clock->clock_started_at ?? $at, $at)
            - $clock->paused_minutes;
        $used     = max(0, $used);

        // Allowed against elapsed, the right way round.
        $met = $clock->response_minutes === null || $used <= $clock->response_minutes;

        $clock->forceFill([
            'response_minutes_used' => $used,
            'response_met'          => $met,
            'response_breached_at'  => $met ? null : $at,
        ])->save();

        $this->record($ticket, SlaEvent::RESPONDED, 'response',
            $met ? 'Answered within target' : 'Answered outside target', $at, $used);

        if (! $met) {
            $this->record($ticket, SlaEvent::BREACHED, 'response',
                "Response took {$used} working minutes against {$clock->response_minutes}", $at, $used);
        }
    }

    /** Reaching a resolved or closed state. */
    public function onResolved(Ticket $ticket, CarbonInterface $at): void
    {
        $clock = $ticket->sla;

        if (! $clock || $clock->resolution_met !== null) {
            return;
        }

        $calendar = $this->calendarFor($clock->sla, $ticket->organization_id);
        $used     = $calendar->workingMinutesBetween($clock->clock_started_at ?? $at, $at)
            - $clock->paused_minutes;
        $used     = max(0, $used);

        $met = $clock->resolution_minutes === null || $used <= $clock->resolution_minutes;

        $clock->forceFill([
            'resolution_minutes_used' => $used,
            'resolution_met'          => $met,
            'resolution_breached_at'  => $met ? null : $at,
        ])->save();

        $this->record($ticket, SlaEvent::RESOLVED, 'resolution',
            $met ? 'Resolved within target' : 'Resolved outside target', $at, $used);

        if (! $met) {
            $this->record($ticket, SlaEvent::BREACHED, 'resolution',
                "Resolution took {$used} working minutes against {$clock->resolution_minutes}", $at, $used);
        }
    }

    /**
     * Working minutes counted so far on a live ticket.
     *
     * Shown next to raw elapsed time on screen. The difference between the two
     * is the most-disputed number in any service review, so it is stated rather
     * than left to be worked out.
     */
    public function minutesUsed(Ticket $ticket): int
    {
        $clock = $ticket->sla;

        if (! $clock || ! $clock->clock_started_at) {
            return 0;
        }

        $calendar = $this->calendarFor($clock->sla, $ticket->organization_id);
        $until    = $clock->paused_at ?? now();

        return max(0, $calendar->workingMinutesBetween($clock->clock_started_at, $until)
            - $clock->paused_minutes);
    }

    // ── Selection ─────────────────────────────────────────────────────────────

    /**
     * The SLA governing a ticket: its contract's, then its account's, then the
     * organisation's fallback.
     */
    public function slaFor(Ticket $ticket): ?Sla
    {
        $query = fn () => Sla::withoutGlobalScope('organization')
            ->where('organization_id', $ticket->organization_id)
            ->where('is_active', true);

        if ($ticket->account_contract_id) {
            $byContract = $query()->where('account_contract_id', $ticket->account_contract_id)->first();

            if ($byContract) {
                return $byContract;
            }
        }

        if ($ticket->account_id) {
            $contractIds = \App\Models\Account\AccountContract::withoutGlobalScope('organization')
                ->where('account_id', $ticket->account_id)
                ->pluck('id');

            if ($contractIds->isNotEmpty()) {
                $byAccount = $query()->whereIn('account_contract_id', $contractIds)->first();

                if ($byAccount) {
                    return $byAccount;
                }
            }
        }

        return $query()->where('is_default', true)->first()
            ?? $query()->whereNull('account_contract_id')->first();
    }

    public function calendarFor(?Sla $sla, int $organizationId): Calendar
    {
        $calendar = $sla?->calendar;

        if ($calendar) {
            return $calendar;
        }

        return app(SupportDefaults::class)->defaultCalendar($organizationId);
    }

    private function record(
        Ticket $ticket,
        string $event,
        ?string $clock,
        ?string $detail,
        ?CarbonInterface $at = null,
        ?int $minutes = null,
    ): void {
        SlaEvent::create([
            'support_ticket_id' => $ticket->id,
            'event'             => $event,
            'clock'             => $clock,
            'detail'            => $detail,
            'working_minutes'   => $minutes,
            'occurred_at'       => $at ?? now(),
        ]);
    }
}
