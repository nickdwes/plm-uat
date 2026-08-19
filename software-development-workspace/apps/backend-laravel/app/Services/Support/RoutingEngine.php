<?php

namespace App\Services\Support;

use App\Models\Account\AccountContract;
use App\Models\Support\AssignmentRule;
use App\Models\Support\ContractEngineer;
use App\Models\Support\ContractSupport;
use App\Models\Support\Team;
use App\Models\Support\Ticket;
use App\Models\Support\TicketEvent;
use App\Models\Support\TicketStatus;
use App\Models\User;

/**
 * Where a ticket goes, decided by who knows the customer.
 *
 * This replaces the round-robin engine. Distributing work by availability
 * ignores the only thing that reliably makes support faster: an engineer who
 * installed a customer's network is not interchangeable with one who has never
 * seen it, and a queue that treats them as equal makes every ticket slower
 * while looking fair.
 *
 * ── The order ──
 *
 *   1. Somebody assigned it by hand — stop, and never override
 *   2. A rule that names a specific person or queue
 *   3. The engineer named on the customer's contract for this category
 *   4. The engineer named on the contract with no category — the generalist
 *   5. The contract's primary team
 *   6. The practice that owns the service category
 *   7. The service desk
 *
 * Six and seven matter as much as three. A ticket nobody can place still has to
 * land somewhere a person will look, and the old module's answer — leave it
 * unassigned — is how things sit untouched for a week.
 */
class RoutingEngine
{
    /**
     * @return array{team_id: ?int, user_id: ?int, contract_engineer_id: ?int, why: string}
     */
    public function route(Ticket $ticket, bool $force = false): array
    {
        /*
         * A manual assignment is a decision somebody made with context this
         * engine does not have. Overriding it loses work, and doing so because
         * another engineer became free is exactly what the brief rules out.
         */
        if (! $force && $ticket->assigned_to !== null) {
            return $this->result($ticket->support_team_id, $ticket->assigned_to,
                $ticket->account_contract_engineer_id, 'Already assigned by hand');
        }

        $contract = $this->contractFor($ticket);
        $support  = $contract ? ContractSupport::withoutGlobalScope('organization')
            ->where('account_contract_id', $contract->id)->first() : null;

        /*
         * 2 — an explicit rule wins over the contract structure, because it is
         * the exception somebody wrote down deliberately. A rule that only
         * names a queue returns that queue and leaves the person to the
         * contract below.
         */
        $ruleTeamId = null;

        if ($decision = $this->byRule($ticket, $ruleTeamId)) {
            return $this->apply($ticket, $decision, $support);
        }

        // 3 and 4 — the contract's own engineers.
        if ($contract) {
            $engineer = $this->contractEngineer($contract->id, $ticket->service_category_id);

            if ($engineer) {
                return $this->apply($ticket, [
                    'team_id' => $ruleTeamId ?? $support?->primary_team_id ?? $this->practiceQueue($ticket),
                    'user_id' => $engineer->user_id,
                    'contract_engineer_id' => $engineer->id,
                    'why' => $engineer->service_category_id
                        ? 'Engineer named on the contract for this service'
                        : 'Engineer named on the contract',
                ], $support);
            }
        }

        // 5 — the queue a rule chose, then the contract's team.
        if ($ruleTeamId) {
            return $this->apply($ticket, [
                'team_id' => $ruleTeamId,
                'user_id' => null,
                'contract_engineer_id' => null,
                'why' => 'Routing rule queue',
            ], $support);
        }

        if ($support?->primary_team_id) {
            return $this->apply($ticket, [
                'team_id' => $support->primary_team_id,
                'user_id' => null,
                'contract_engineer_id' => null,
                'why' => 'Contract support team',
            ], $support);
        }

        // 6 — the practice that owns this kind of work.
        if ($queue = $this->practiceQueue($ticket)) {
            return $this->apply($ticket, [
                'team_id' => $queue,
                'user_id' => null,
                'contract_engineer_id' => null,
                'why' => 'Practice that owns this service category',
            ], $support);
        }

        // 7 — the desk. Somewhere a person looks, rather than nowhere.
        return $this->apply($ticket, [
            'team_id' => $this->serviceDeskQueue($ticket->organization_id),
            'user_id' => null,
            'contract_engineer_id' => null,
            'why' => 'No contract or category matched — sent to the service desk',
        ], $support);
    }

    /**
     * Who could take this, ranked, for a person to choose from.
     *
     * Suggestions rather than an assignment. Where several engineers are
     * equally placed the choice belongs to the analyst or the team lead, who
     * know about the leave nobody recorded and the customer who will not work
     * with one of them.
     */
    public function suggest(Ticket $ticket, int $limit = 8): array
    {
        $contract = $this->contractFor($ticket);
        $candidates = [];

        if ($contract) {
            $named = ContractEngineer::withoutGlobalScope('organization')
                ->where('account_contract_id', $contract->id)
                ->where('is_active', true)
                ->with('engineer:id,name')
                ->get();

            foreach ($named as $row) {
                $matchesCategory = $row->service_category_id === $ticket->service_category_id;

                $candidates[$row->user_id] = [
                    'user_id' => $row->user_id,
                    'name'    => $row->engineer?->name,
                    'reason'  => $matchesCategory
                        ? 'Named on the contract for this service'
                        : ($row->service_category_id ? 'Named on the contract for another service'
                                                     : 'Named on the contract'),
                    // Contract knowledge outranks everything else here.
                    'score'   => $matchesCategory ? 100 : ($row->role === ContractEngineer::ROLE_PRIMARY ? 80 : 60),
                ];
            }
        }

        // Then the practice, for cover when the named engineers are unavailable.
        foreach ($this->practiceMembers($ticket) as $userId => $name) {
            if (isset($candidates[$userId])) {
                continue;
            }

            $candidates[$userId] = [
                'user_id' => $userId,
                'name'    => $name,
                'reason'  => 'In the practice that owns this service',
                'score'   => 40,
            ];
        }

        if ($candidates === []) {
            return [];
        }

        $load = $this->workload($ticket->organization_id, array_keys($candidates));

        foreach ($candidates as $userId => &$row) {
            $row += $load[$userId] ?? ['open' => 0, 'pending' => 0, 'at_risk' => 0];

            /*
             * Workload nudges the order; it never decides it. A busy engineer
             * who knows the estate still beats an idle one who does not, which
             * is why the weighting cannot close a 60-point gap.
             */
            $row['score'] -= min(20, $row['open'] * 2);
            $row['available'] = $this->accepts($ticket->organization_id, $userId);

            if (! $row['available']) {
                $row['score'] -= 50;
                $row['reason'] .= ' — not taking work at the moment';
            }
        }
        unset($row);

        usort($candidates, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice(array_values($candidates), 0, $limit);
    }

    /** Open, pending and at-risk counts, for a human to weigh. */
    public function workload(int $organizationId, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $rows = Ticket::withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->whereIn('assigned_to', $userIds)
            ->whereHas('status', fn ($q) => $q->whereIn('state', [
                TicketStatus::STATE_OPEN, TicketStatus::STATE_PENDING,
            ]))
            ->with(['status:id,state', 'sla:support_ticket_id,resolution_due_at,response_met,resolution_met'])
            ->get(['id', 'assigned_to', 'ticket_status_id']);

        $out = [];

        foreach ($rows as $ticket) {
            $id = $ticket->assigned_to;
            $out[$id] ??= ['open' => 0, 'pending' => 0, 'at_risk' => 0];

            $ticket->status?->state === TicketStatus::STATE_PENDING
                ? $out[$id]['pending']++
                : $out[$id]['open']++;

            $due = $ticket->sla?->resolution_due_at;

            // Inside four hours of the target, or already past it.
            if ($due && $ticket->sla?->resolution_met === null && $due->lt(now()->addHours(4))) {
                $out[$id]['at_risk']++;
            }
        }

        return $out;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function byRule(Ticket $ticket, ?int &$ruleTeamId = null): ?array
    {
        $rules = AssignmentRule::withoutGlobalScope('organization')
            ->where('organization_id', $ticket->organization_id)
            ->where('is_active', true)
            ->orderBy('position')->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            if (! $rule->matches($ticket)) {
                continue;
            }

            // A rule that only narrows the queue leaves the person to the
            // contract structure below, rather than blanking it.
            if ($rule->assign_mode === AssignmentRule::MODE_SPECIFIC && $rule->assign_user_id) {
                return [
                    'team_id' => $rule->assign_team_id ?? $ticket->support_team_id,
                    'user_id' => $rule->assign_user_id,
                    'contract_engineer_id' => null,
                    'why' => "Rule: {$rule->name}",
                ];
            }

            if ($rule->assign_team_id) {
                /*
                 * The rule settles the queue. Who inside it takes the ticket is
                 * still the contract's question, so `prefer` decides whether to
                 * carry on looking for the named engineer or leave it on the
                 * queue for somebody to pick up.
                 */
                if ($rule->prefer === AssignmentRule::PREFER_TEAM) {
                    return [
                        'team_id' => $rule->assign_team_id,
                        'user_id' => null,
                        'contract_engineer_id' => null,
                        'why' => "Rule: {$rule->name}",
                    ];
                }

                $ruleTeamId = $rule->assign_team_id;
                break;
            }
        }

        return null;
    }

    private function contractFor(Ticket $ticket): ?AccountContract
    {
        if ($ticket->account_contract_id) {
            return AccountContract::withoutGlobalScope('organization')->find($ticket->account_contract_id);
        }

        if (! $ticket->account_id) {
            return null;
        }

        // The live one. A lapsed contract is not a support entitlement, and
        // routing on it would promise cover that ended.
        return AccountContract::withoutGlobalScope('organization')
            ->where('account_id', $ticket->account_id)
            ->whereIn('status', ['active', 'in_progress'])
            ->orderByDesc('start_date')
            ->first();
    }

    private function contractEngineer(int $contractId, ?int $categoryId): ?ContractEngineer
    {
        $base = fn () => ContractEngineer::withoutGlobalScope('organization')
            ->where('account_contract_id', $contractId)
            ->where('is_active', true);

        if ($categoryId) {
            $exact = $base()->where('service_category_id', $categoryId)
                ->orderByRaw("case when role = 'primary' then 0 else 1 end")
                ->first();

            if ($exact) {
                return $exact;
            }
        }

        // The generalist named on the contract with no category against them.
        return $base()->whereNull('service_category_id')
            ->orderByRaw("case when role = 'primary' then 0 else 1 end")
            ->first();
    }

    private function practiceQueue(Ticket $ticket): ?int
    {
        $category = $ticket->serviceCategory;

        /*
         * The queue named on the category, first. Most organisations take
         * tickets long before they finish setting their practices up, and
         * without this the fallback quietly does nothing and everything lands
         * on the service desk.
         */
        if ($category?->default_team_id) {
            return $category->default_team_id;
        }

        $practiceId = $category?->org_practice_id;

        if (! $practiceId) {
            return null;
        }

        return Team::withoutGlobalScope('organization')
            ->where('organization_id', $ticket->organization_id)
            ->where('org_practice_id', $practiceId)
            ->where('is_active', true)
            ->value('id');
    }

    private function practiceMembers(Ticket $ticket): array
    {
        $practiceId = $ticket->serviceCategory?->org_practice_id;

        if (! $practiceId) {
            return [];
        }

        return \App\Models\Support\Engineer::withoutGlobalScope('organization')
            ->where('organization_id', $ticket->organization_id)
            ->where('org_practice_id', $practiceId)
            ->where('is_active', true)
            ->with('user:id,name')
            ->get()
            ->mapWithKeys(fn ($e) => [$e->user_id => $e->user?->name])
            ->all();
    }

    private function serviceDeskQueue(int $organizationId): ?int
    {
        return Team::withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->where('is_service_desk', true)
            ->value('id');
    }

    private function accepts(int $organizationId, int $userId): bool
    {
        $engineer = \App\Models\Support\Engineer::withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->first();

        // Somebody with no engineer record is not thereby unavailable — most
        // people are simply not set up yet, and treating that as "on leave"
        // would empty every suggestion list on day one.
        return $engineer?->available() ?? true;
    }

    private function apply(Ticket $ticket, array $decision, ?ContractSupport $support): array
    {
        $before = ['team' => $ticket->support_team_id, 'user' => $ticket->assigned_to];

        $ticket->forceFill([
            'support_team_id'              => $decision['team_id'] ?? $ticket->support_team_id,
            'assigned_to'                  => $decision['user_id'] ?? $ticket->assigned_to,
            'account_contract_engineer_id' => $decision['contract_engineer_id'] ?? null,
            // The analyst who follows this customer, if the contract names one.
            'service_desk_user_id'         => $ticket->service_desk_user_id ?? $support?->service_desk_user_id,
        ])->save();

        if ($before['team'] !== $ticket->support_team_id || $before['user'] !== $ticket->assigned_to) {
            TicketEvent::create([
                'support_ticket_id' => $ticket->id,
                'event'      => TicketEvent::ASSIGNED,
                'field'      => 'routing',
                'from_value' => $before['user'] ? "user {$before['user']}" : ($before['team'] ? "team {$before['team']}" : null),
                'to_value'   => $ticket->assigned_to ? "user {$ticket->assigned_to}" : "team {$ticket->support_team_id}",
                'reason'     => $decision['why'],
                'automatic'  => true,
                'occurred_at' => now(),
            ]);
        }

        return $this->result($ticket->support_team_id, $ticket->assigned_to,
            $ticket->account_contract_engineer_id, $decision['why']);
    }

    private function result(?int $teamId, ?int $userId, ?int $engineerId, string $why): array
    {
        return [
            'team_id' => $teamId,
            'user_id' => $userId,
            'contract_engineer_id' => $engineerId,
            'why' => $why,
        ];
    }
}
