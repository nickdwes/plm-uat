<?php

namespace App\Services\Support;

use App\Models\Support\Escalation;
use App\Models\Support\Survey;
use App\Models\Support\Ticket;
use App\Models\Support\TicketEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The numbers a service review is argued from.
 *
 * The old module could not answer "did we meet the SLA last month" at all —
 * the data was there and nothing read it, so every review was assembled by
 * hand in a spreadsheet the week before the meeting, which is why the figures
 * differed depending on who built them.
 *
 * Three rules run through everything here, and they are the whole difference
 * between a report and a number somebody made up:
 *
 *  1. **Every percentage carries its denominator.** A rate is reported with the
 *     count it came from and the count held out of it. "94%" on its own is
 *     unarguable in the wrong direction: nobody can tell whether it is 94% of
 *     three hundred tickets or of seventeen.
 *
 *  2. **Nothing is excluded silently.** Tickets with no SLA and tickets merged
 *     away are held out of compliance — they have no target to judge and no
 *     life of their own — but they are counted and reported, so the population
 *     adds up in front of the customer.
 *
 *  3. **An empty set has no rate.** Zero of zero is not a hundred per cent and
 *     not nought. It is null, and it is drawn as "no tickets", because a green
 *     100% on an account that raised nothing is the kind of figure that gets a
 *     report thrown out of the room.
 */
class ServiceReview
{
    /** Backlog age buckets, in calendar days, upper bound exclusive. */
    private const AGE_BUCKETS = [
        ['label' => 'Under a day', 'from' => 0,  'to' => 1],
        ['label' => '1–3 days',    'from' => 1,  'to' => 3],
        ['label' => '3–7 days',    'from' => 3,  'to' => 7],
        ['label' => '1–2 weeks',   'from' => 7,  'to' => 14],
        ['label' => '2–4 weeks',   'from' => 14, 'to' => 30],
        ['label' => 'Over a month','from' => 30, 'to' => null],
    ];

    // ── The pack ──────────────────────────────────────────────────────────────

    /** Everything, for one account and one period. */
    public function pack(ReportWindow $window): array
    {
        return [
            'window'       => $window->describe(),
            'volumes'      => $this->volumes($window),
            'sla'          => $this->compliance($window),
            'response'     => $this->responseTimes($window),
            'backlog'      => $this->backlog($window),
            'satisfaction' => $this->satisfaction($window),
            'themes'       => $this->themes($window),
            'escalations'  => $this->escalations($window),
        ];
    }

    // ── Volumes ───────────────────────────────────────────────────────────────

    /**
     * What came in, what went out, and whether the two balanced.
     *
     * Raised and resolved are counted over the same window but are not the same
     * tickets: a ticket raised in June and resolved in July belongs to June's
     * intake and July's output. Netting them is the point — a desk that resolves
     * fewer than it takes is growing a backlog whatever its SLA figure says.
     */
    public function volumes(ReportWindow $window): array
    {
        $raised = (clone $this->base($window))
            ->whereBetween('opened_at', [$window->from, $window->until])->count();

        /*
         * Counted from the event trail, not from `resolved_at`. Reopening a
         * ticket clears that column — rightly, since a resolution date on an
         * open ticket is a false fact — but it meant a report run in October
         * disagreed with the same report run in August about July. The events
         * are written once and never revised.
         */
        $resolved = (clone $this->base($window))
            ->whereHas('events', fn (Builder $q) => $q
                ->where('event', TicketEvent::RESOLVED)
                ->whereBetween('occurred_at', [$window->from, $window->until]))
            ->count();

        $reopened = (clone $this->base($window))
            ->whereHas('events', fn (Builder $q) => $q
                ->where('event', TicketEvent::REOPENED)
                ->whereBetween('occurred_at', [$window->from, $window->until]))
            ->count();

        return [
            'raised'      => $raised,
            'resolved'    => $resolved,
            'net'         => $raised - $resolved,
            'reopened'    => $reopened,
            'per_day'     => round($raised / $window->days(), 1),
            'by_priority' => $this->breakdown($window, 'priority_id', 'priority'),
            'by_type'     => $this->breakdown($window, 'ticket_type_id', 'type'),
            'by_category' => $this->breakdown($window, 'service_category_id', 'serviceCategory'),
            'by_source'   => (clone $this->base($window))
                ->whereBetween('opened_at', [$window->from, $window->until])
                ->groupBy('source')
                ->select('source', DB::raw('count(*) as total'))
                ->pluck('total', 'source')->all(),
        ];
    }

    // ── SLA compliance ────────────────────────────────────────────────────────

    /**
     * Whether the targets were met.
     *
     * Which tickets belong to a month is the question every SLA report gets
     * wrong. Counting tickets *raised* in the window flatters it: one raised on
     * the 31st and answered on the 2nd has no outcome yet, so it drops out and
     * the month looks cleaner than it was. Counting only tickets *answered* in
     * the window flatters it differently: a ticket sitting unanswered and long
     * past its target is not answered at all, so it escapes the figure entirely
     * — the worst tickets on the desk are the ones invisible to it.
     *
     * So a ticket counts in the window where its clock *settled*, and blowing
     * the target is a settlement — stamped by the hourly sweep at the moment
     * the target passed, whether or not anybody has touched the ticket since.
     * One moment per clock means one month per ticket: a ticket that breached
     * in July and was answered in September is July's failure, counted once,
     * and no report run later can move it.
     */
    public function compliance(ReportWindow $window): array
    {
        $withSla = fn () => (clone $this->base($window))->whereHas('sla');

        $answered = (clone $withSla())
            ->whereHas('sla', fn (Builder $s) => $s
                ->whereBetween('response_settled_at', [$window->from, $window->until]))
            ->with(['sla', 'priority:id,name'])
            ->get();

        $closed = (clone $withSla())
            ->whereHas('sla', fn (Builder $s) => $s
                ->whereBetween('resolution_settled_at', [$window->from, $window->until]))
            ->with(['sla', 'priority:id,name'])
            ->get();

        /*
         * Held out and said so. A ticket with no SLA has no target to judge —
         * a walk-up, or an account whose contract never named one — and a
         * merged ticket's life continued somewhere else. Dropping either
         * without a word is how a denominator quietly shrinks to fit.
         */
        $noSla = (clone $this->base($window))
            ->whereBetween('opened_at', [$window->from, $window->until])
            ->whereDoesntHave('sla')->count();

        $merged = (clone $this->base($window, withMerged: true))
            ->whereBetween('opened_at', [$window->from, $window->until])
            ->whereNotNull('merged_at')->count();

        return [
            'response'   => $this->rate($answered, 'response_met'),
            'resolution' => $this->rate($closed, 'resolution_met'),
            'by_priority' => $this->complianceByPriority($answered, $closed),
            'excluded'   => [
                'no_sla' => $noSla,
                'merged' => $merged,
                'note'   => $this->exclusionNote($noSla, $merged),
            ],
            'worst' => $this->slowest($closed),
        ];
    }

    /**
     * The misses, named — including the ones nobody ever got to.
     *
     * Listing only tickets that were eventually fixed late leaves out the
     * tickets that were never fixed at all, which are worse by every measure
     * and are the ones a customer arrives at the meeting holding. They have no
     * "took" figure because they are still running, so they are shown as still
     * open and sorted to the top rather than being quietly dropped for lacking
     * a number.
     *
     * @param  \Illuminate\Support\Collection<int, Ticket>  $tickets
     */
    private function slowest($tickets): array
    {
        return $tickets
            ->filter(fn (Ticket $t) => $t->sla?->resolution_breached_at !== null)
            ->map(function (Ticket $t) {
                $stillOpen = $t->sla->resolution_met === null;

                return [
                    'reference'  => $t->reference,
                    'subject'    => $t->subject,
                    'priority'   => $t->priority?->name,
                    'target'     => $t->sla->resolution_minutes,
                    'took'       => $stillOpen ? null : $t->sla->resolution_minutes_used,
                    'over_by'    => $stillOpen
                        ? null
                        : $t->sla->resolution_minutes_used - ($t->sla->resolution_minutes ?? 0),
                    'still_open' => $stillOpen,
                    'since'      => $t->sla->resolution_breached_at->toIso8601String(),
                ];
            })
            // Still open first, then the ones that ran furthest past target.
            ->sortBy([
                fn (array $a, array $b) => ($b['still_open'] <=> $a['still_open']),
                fn (array $a, array $b) => ($b['over_by'] ?? PHP_INT_MAX) <=> ($a['over_by'] ?? PHP_INT_MAX),
            ])
            ->take(10)->values()->all();
    }

    /**
     * Met, missed and the rate — or null, when there is nothing to rate.
     *
     * @param  \Illuminate\Support\Collection<int, Ticket>  $tickets
     */
    private function rate($tickets, string $field): array
    {
        $judged = $tickets->filter(fn (Ticket $t) => $t->sla?->{$field} !== null);
        $met    = $judged->filter(fn (Ticket $t) => $t->sla->{$field} === true)->count();
        $total  = $judged->count();

        /*
         * Tickets in the population whose clock has not been judged yet: they
         * breached, so they are in the window, but nobody has answered or
         * resolved them and the flag is still null. They are misses in every
         * sense that matters to a customer, so they are counted as such — but
         * counted separately too, because "missed and still open" is a
         * different conversation from "missed and dealt with".
         */
        $outstanding = $tickets->count() - $total;

        $total += $outstanding;

        return [
            'met'         => $met,
            'missed'      => $total - $met,
            'outstanding' => $outstanding,
            'total'       => $total,
            'rate'        => $total > 0 ? round($met / $total * 100, 1) : null,
        ];
    }

    private function complianceByPriority($answered, $closed): array
    {
        $names = $answered->concat($closed)
            ->pluck('priority.name', 'priority_id')->filter();

        return $names->map(fn ($name, $id) => [
            'priority'   => $name,
            'response'   => $this->rate($answered->where('priority_id', $id), 'response_met'),
            'resolution' => $this->rate($closed->where('priority_id', $id), 'resolution_met'),
        ])->values()->all();
    }

    private function exclusionNote(int $noSla, int $merged): ?string
    {
        $parts = [];

        if ($noSla > 0) {
            $parts[] = $noSla . ' ' . ($noSla === 1 ? 'ticket has' : 'tickets have')
                . ' no SLA and cannot be judged against a target';
        }

        if ($merged > 0) {
            $parts[] = $merged . ' merged into another ticket';
        }

        return $parts ? 'Held out of the figures above: ' . implode('; ', $parts) . '.' : null;
    }

    // ── How long people waited ────────────────────────────────────────────────

    /**
     * Response and resolution times, in working minutes.
     *
     * Median and 90th percentile, never a mean. One ticket answered after a
     * bank holiday weekend drags a mean far enough to make a good month look
     * bad, and one ticket answered instantly drags it the other way; the mean
     * of a wait time describes nobody's experience. The median is the ticket in
     * the middle and the 90th is the tail — between them they say what most
     * people got and how bad the bad end was.
     *
     * The minutes are the ones the clock recorded when it stopped, not a fresh
     * calculation: a calendar edited since would otherwise restate history.
     */
    public function responseTimes(ReportWindow $window): array
    {
        $responded = (clone $this->base($window))
            ->whereBetween('first_response_at', [$window->from, $window->until])
            ->join('support_ticket_slas as s', 's.support_ticket_id', '=', 'support_tickets.id')
            ->whereNotNull('s.response_minutes_used')
            ->pluck('s.response_minutes_used')->map(fn ($m) => (int) $m)->sort()->values()->all();

        $resolved = (clone $this->base($window))
            ->whereBetween('resolved_at', [$window->from, $window->until])
            ->join('support_ticket_slas as s', 's.support_ticket_id', '=', 'support_tickets.id')
            ->whereNotNull('s.resolution_minutes_used')
            ->pluck('s.resolution_minutes_used')->map(fn ($m) => (int) $m)->sort()->values()->all();

        return [
            'response'   => $this->spread($responded),
            'resolution' => $this->spread($resolved),
            'note'       => 'Working minutes against the support calendar, not elapsed time.',
        ];
    }

    /**
     * @param  list<int>  $sorted  ascending
     */
    private function spread(array $sorted): array
    {
        return [
            'count'  => count($sorted),
            'median' => $this->percentile($sorted, 0.5),
            'p90'    => $this->percentile($sorted, 0.9),
            'worst'  => $sorted ? end($sorted) : null,
        ];
    }

    /**
     * Nearest-rank percentile: the smallest value at or above the given share
     * of the set. Interpolating between two tickets would invent a wait nobody
     * had, and the figure has to survive being checked by hand in a meeting.
     *
     * @param  list<int>  $sorted  ascending
     */
    private function percentile(array $sorted, float $p): ?int
    {
        if (! $sorted) {
            return null;
        }

        $rank = (int) ceil($p * count($sorted));

        return $sorted[max(0, $rank - 1)];
    }

    // ── Backlog ───────────────────────────────────────────────────────────────

    /**
     * What is still open, and how long it has been.
     *
     * As of now rather than the end of the window: a backlog is a present-tense
     * fact, and a customer asking "what's outstanding" means today. Age is in
     * calendar days, not working hours — the SLA is judged in working time, but
     * a ticket open since a fortnight ago has been open a fortnight whatever
     * the calendar says about weekends, and quoting nine working days to
     * somebody who has waited fourteen reads as an excuse.
     */
    public function backlog(ReportWindow $window): array
    {
        $open = (clone $this->base($window))
            ->whereHas('status', fn (Builder $q) => $q->whereIn('state', ['open', 'pending']))
            ->with(['status:id,name,state', 'priority:id,name', 'assignee:id,name', 'sla', 'account:id,name'])
            ->get();

        $now = now();

        $buckets = collect(self::AGE_BUCKETS)->map(function (array $bucket) use ($open, $now) {
            $in = $open->filter(function (Ticket $t) use ($bucket, $now) {
                $days = ($t->opened_at ?? $t->created_at)->diffInDays($now);

                return $days >= $bucket['from'] && ($bucket['to'] === null || $days < $bucket['to']);
            });

            return [
                'label'      => $bucket['label'],
                'total'      => $in->count(),
                /*
                 * Two numbers that change what a bucket means. An old ticket
                 * that is breached is a failure; an old ticket that is waiting
                 * on the customer is not ours to fix. Reporting the bucket
                 * alone invites both to be read as the same thing.
                 */
                'breached'   => $in->filter(fn (Ticket $t) => $t->sla
                    && ($t->sla->resolution_breached_at !== null || $t->sla->response_breached_at !== null))->count(),
                'unassigned' => $in->whereNull('assigned_to')->count(),
                'with_customer' => $in->filter(fn (Ticket $t) => $t->status?->state === 'pending')->count(),
            ];
        })->all();

        return [
            'total'    => $open->count(),
            'buckets'  => $buckets,
            'unassigned' => $open->whereNull('assigned_to')->count(),
            'breached' => $open->filter(fn (Ticket $t) => $t->sla
                && ($t->sla->resolution_breached_at !== null))->count(),
            'oldest'   => $open->sortBy(fn (Ticket $t) => $t->opened_at ?? $t->created_at)
                ->take(10)->values()
                ->map(fn (Ticket $t) => [
                    'reference' => $t->reference,
                    'subject'   => $t->subject,
                    'account'   => $t->account?->name,
                    'status'    => $t->status?->name,
                    'priority'  => $t->priority?->name,
                    'assignee'  => $t->assignee?->name,
                    'days'      => (int) ($t->opened_at ?? $t->created_at)->diffInDays($now),
                    'breached'  => (bool) ($t->sla?->resolution_breached_at),
                ])->all(),
        ];
    }

    // ── Satisfaction ──────────────────────────────────────────────────────────

    /**
     * What customers said.
     *
     * The response rate is reported next to the score and is not optional. A
     * 4.9 from four people out of two hundred is four people's opinion, and a
     * report that prints the score without the rate is inviting a decision to
     * be made on it.
     */
    public function satisfaction(ReportWindow $window): array
    {
        $sent = Survey::whereBetween('sent_at', [$window->from, $window->until])
            ->when($window->accountId, fn (Builder $q) => $q
                ->whereHas('ticket', fn (Builder $t) => $t->where('account_id', $window->accountId)))
            ->with('ticket:id,reference,subject,account_id')
            ->get();

        $answered = $sent->whereNotNull('responded_at');
        $rated    = $answered->whereNotNull('rating');

        $judgement = fn (string $field) => $answered->groupBy($field)
            ->map->count()->filter(fn ($_, $k) => $k !== '')->all();

        return [
            'sent'          => $sent->count(),
            'responded'     => $answered->count(),
            'response_rate' => $sent->count() > 0
                ? round($answered->count() / $sent->count() * 100, 1)
                : null,
            'average'       => $rated->count() > 0
                ? round($rated->avg('rating'), 2)
                : null,
            'ratings'       => collect(range(1, 5))
                ->mapWithKeys(fn (int $n) => [$n => $rated->where('rating', $n)->count()])->all(),
            'satisfaction'   => $judgement('satisfaction'),
            'timeliness'     => $judgement('timeliness'),
            'resolved_fully' => $judgement('resolved_fully'),
            /*
             * The comments, verbatim. The score is what gets put on a slide and
             * the comments are what actually change anything.
             */
            'comments' => $answered->filter(fn (Survey $s) => filled($s->comment))
                ->sortByDesc('responded_at')->take(20)->values()
                ->map(fn (Survey $s) => [
                    'reference' => $s->ticket?->reference,
                    'subject'   => $s->ticket?->subject,
                    'rating'    => $s->rating,
                    'comment'   => $s->comment,
                    'on'        => $s->responded_at?->toDateString(),
                ])->all(),
            'note' => $rated->count() > 0 && $rated->count() < 5
                ? 'Too few responses to read as a trend — ' . $rated->count()
                    . ' ' . ($rated->count() === 1 ? 'person' : 'people') . ' answered.'
                : null,
        ];
    }

    // ── What kept coming back ─────────────────────────────────────────────────

    /**
     * The repeat business.
     *
     * A service review that lists thirty tickets teaches nobody anything; the
     * useful sentence is "fourteen of these were the same printer". Counted by
     * category and by the kit involved, because those are the two groupings
     * that lead to somebody fixing a cause rather than answering it again.
     */
    public function themes(ReportWindow $window): array
    {
        $byCategory = $this->breakdown($window, 'service_category_id', 'serviceCategory');

        $byAsset = (clone $this->base($window))
            ->whereBetween('opened_at', [$window->from, $window->until])
            ->join('support_ticket_assets as ta', 'ta.support_ticket_id', '=', 'support_tickets.id')
            ->join('assets as a', 'a.id', '=', 'ta.asset_id')
            ->groupBy('a.id', 'a.name', 'a.asset_tag')
            ->select('a.name', 'a.asset_tag', DB::raw('count(*) as total'))
            ->orderByDesc('total')->limit(10)->get()
            ->map(fn ($row) => [
                'label' => trim(($row->asset_tag ? $row->asset_tag . ' — ' : '') . $row->name),
                'total' => (int) $row->total,
            ])->all();

        return [
            'by_category' => $byCategory,
            'by_asset'    => $byAsset,
            'repeat_note' => $byAsset && $byAsset[0]['total'] > 1
                ? $byAsset[0]['label'] . ' accounted for ' . $byAsset[0]['total'] . ' tickets.'
                : null,
        ];
    }

    // ── Escalations ───────────────────────────────────────────────────────────

    /** Judgement calls, not the SLA ladder — those are different questions. */
    public function escalations(ReportWindow $window): array
    {
        $rows = Escalation::whereBetween('escalated_at', [$window->from, $window->until])
            ->whereHas('ticket', fn (Builder $q) => $q
                ->when($window->accountId, fn (Builder $b) => $b->where('account_id', $window->accountId)))
            ->get();

        return [
            'total'    => $rows->count(),
            'open'     => $rows->whereNull('resolved_at')->count(),
            'by_level' => $rows->groupBy('level')->map->count()
                ->mapWithKeys(fn ($n, $level) => [Escalation::LEVELS[$level] ?? $level => $n])->all(),
        ];
    }

    // ── Shared machinery ──────────────────────────────────────────────────────

    /**
     * The population every figure is drawn from.
     *
     * Merged tickets are out by default. A merged ticket's messages, time and
     * outcome all belong to the ticket it was folded into, so counting both is
     * counting one piece of work twice — and it is always the busy accounts,
     * where duplicates get merged, that it inflates.
     */
    private function base(ReportWindow $window, bool $withMerged = false): Builder
    {
        return Ticket::query()
            ->when(! $withMerged, fn (Builder $q) => $q->whereNull('merged_at'))
            ->when($window->accountId, fn (Builder $q) => $q->where('account_id', $window->accountId))
            ->when($window->teamId, fn (Builder $q) => $q->where('support_team_id', $window->teamId));
    }

    /** Tickets raised in the window, grouped by one column, biggest first. */
    private function breakdown(ReportWindow $window, string $column, string $relation): array
    {
        $counts = (clone $this->base($window))
            ->whereBetween('opened_at', [$window->from, $window->until])
            ->groupBy($column)
            ->select($column, DB::raw('count(*) as total'))
            ->pluck('total', $column);

        $model = Ticket::make()->{$relation}()->getRelated();

        $names = $model->newQuery()->withoutGlobalScopes()
            ->whereIn('id', $counts->keys()->filter())
            ->pluck('name', 'id');

        return $counts->map(fn ($total, $id) => [
            /* An unset category is a real answer — usually the largest one, and
             * the reason nobody can say what the desk spends its time on. */
            'label' => $names[$id] ?? 'Not set',
            'total' => (int) $total,
        ])->sortByDesc('total')->values()->all();
    }
}
