<?php

namespace App\Services\Support;

use App\Models\Support\AssignmentRule;
use App\Models\Support\Team;
use App\Models\Support\Ticket;
use App\Models\Support\TicketStatus;
use Illuminate\Support\Facades\DB;

/**
 * Deciding whose queue a ticket lands in.
 *
 * The old module had no answer to this at all — assignment was delegated to the
 * tasks module, so a ticket was unowned until somebody made a task out of it.
 * In practice that means everything sits in one shared pile and the loudest
 * customer gets served first.
 *
 * ── First matching rule wins ──
 *
 * In position order, and the first match ends it. Rules that accumulate — this
 * one sets the team, that one sets the person — are impossible to reason about
 * once there are eight of them and somebody is asking why a ticket went to the
 * wrong place.
 */
class AssignmentEngine
{
    /**
     * Route a ticket, if a rule says so.
     *
     * Never overrides an assignment somebody made by hand. A rule that reroutes
     * a ticket an agent has already picked up is a rule that loses work.
     *
     * @return array{team_id: ?int, user_id: ?int, rule: ?string}
     */
    public function apply(Ticket $ticket, bool $force = false): array
    {
        if (! $force && $ticket->assigned_to !== null) {
            return ['team_id' => $ticket->support_team_id, 'user_id' => $ticket->assigned_to, 'rule' => null];
        }

        $rules = AssignmentRule::withoutGlobalScope('organization')
            ->where('organization_id', $ticket->organization_id)
            ->where('is_active', true)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            if (! $rule->matches($ticket)) {
                continue;
            }

            $teamId = $rule->assign_team_id ?? $ticket->support_team_id;
            $userId = $this->pick($rule, $teamId, $ticket);

            $ticket->forceFill(array_filter([
                'support_team_id' => $teamId,
                'assigned_to'     => $userId,
            ], fn ($v) => $v !== null))->save();

            return ['team_id' => $teamId, 'user_id' => $userId, 'rule' => $rule->name];
        }

        return ['team_id' => $ticket->support_team_id, 'user_id' => $ticket->assigned_to, 'rule' => null];
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function pick(AssignmentRule $rule, ?int $teamId, Ticket $ticket): ?int
    {
        return match ($rule->assign_mode) {
            AssignmentRule::MODE_SPECIFIC    => $rule->assign_user_id,
            AssignmentRule::MODE_ROUND_ROBIN => $this->roundRobin($teamId, $ticket),
            AssignmentRule::MODE_LEAST_BUSY  => $this->leastBusy($teamId, $ticket),
            default                          => null,
        };
    }

    /**
     * Whoever went longest without being handed something.
     *
     * Measured on the team's own tickets rather than a counter, so somebody
     * who joins mid-week is at the front of the queue rather than behind
     * everyone else's history — and a counter cannot drift from the truth
     * because there is no counter.
     */
    private function roundRobin(?int $teamId, Ticket $ticket): ?int
    {
        $candidates = $this->candidates($teamId);

        if ($candidates === []) {
            return null;
        }

        $lastAssigned = Ticket::withoutGlobalScope('organization')
            ->where('organization_id', $ticket->organization_id)
            ->whereIn('assigned_to', $candidates)
            ->select('assigned_to', DB::raw('max(id) as last_id'))
            ->groupBy('assigned_to')
            ->pluck('last_id', 'assigned_to');

        $best = null;
        $bestSeen = null;

        foreach ($candidates as $userId) {
            // Never assigned anything is the strongest claim there is.
            $seen = $lastAssigned[$userId] ?? -1;

            if ($bestSeen === null || $seen < $bestSeen) {
                $best = $userId;
                $bestSeen = $seen;
            }
        }

        return $best;
    }

    /**
     * Whoever has the fewest open tickets.
     *
     * Counts open and pending, not resolved: somebody holding fifteen tickets
     * that are all waiting on suppliers has fifteen things to chase, and
     * handing them a sixteenth because their "active" count looked low is how
     * a queue quietly buries one person.
     */
    private function leastBusy(?int $teamId, Ticket $ticket): ?int
    {
        $candidates = $this->candidates($teamId);

        if ($candidates === []) {
            return null;
        }

        $load = Ticket::withoutGlobalScope('organization')
            ->where('organization_id', $ticket->organization_id)
            ->whereIn('assigned_to', $candidates)
            ->whereHas('status', fn ($q) => $q->whereIn('state', [
                TicketStatus::STATE_OPEN, TicketStatus::STATE_PENDING,
            ]))
            ->select('assigned_to', DB::raw('count(*) as open_count'))
            ->groupBy('assigned_to')
            ->pluck('open_count', 'assigned_to');

        $best = null;
        $bestLoad = null;

        foreach ($candidates as $userId) {
            $count = (int) ($load[$userId] ?? 0);

            if ($bestLoad === null || $count < $bestLoad) {
                $best = $userId;
                $bestLoad = $count;
            }
        }

        return $best;
    }

    private function candidates(?int $teamId): array
    {
        if (! $teamId) {
            return [];
        }

        return Team::withoutGlobalScope('organization')->find($teamId)?->assignableUserIds() ?? [];
    }
}
