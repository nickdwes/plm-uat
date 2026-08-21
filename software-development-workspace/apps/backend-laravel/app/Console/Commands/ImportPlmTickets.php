<?php

namespace App\Console\Commands;

use App\Models\Account\AccountContact;
use App\Models\Account\AccountContract;
use App\Models\Organization;
use App\Models\PlmImportMap;
use App\Models\Support\PriorityMatrix;
use App\Models\Support\Ticket;
use App\Models\Support\TicketImpact;
use App\Models\Support\TicketMessage;
use App\Models\Support\TicketEvent;
use App\Models\Support\TicketStatus;
use App\Models\Support\TicketType;
use App\Models\Support\TicketUrgency;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bring the ticket history across from the old PLM.
 *
 * Three hundred and seventy-two tickets and the six and a half thousand
 * emails that make up their conversations. Without them the desk starts at
 * zero: no service review has a prior month to compare against, "has this
 * customer had this problem before" has no answer, and the knowledge base has
 * nothing to have been written from.
 *
 * ── The desk's machinery is deliberately not run ──
 *
 * These tickets are created as records rather than raised as work. A ticket
 * from 2023 must not fire an automation written in 2026, must not start an
 * SLA clock, must not route itself to a team and must not notify anybody. So
 * nothing here goes through TicketService: it would do all four, and the first
 * sign would be a queue of three hundred year-old tickets assigned overnight.
 *
 * ── No service level is invented ──
 *
 * The old clock read its targets from a column that does not exist, so every
 * compliance figure it produced was computed from null. There is no historic
 * compliance to import, and attaching clocks now would manufacture one — the
 * sweep would mark three hundred settled tickets as breached this week. They
 * arrive with no SLA, and the service review already counts and names tickets
 * it cannot judge.
 *
 * ── Nobody worked these tickets ──
 *
 * The old schema has no assignee, on the ticket or on the resolution. That
 * cannot be recovered by importing more carefully, so every historic ticket
 * arrives unassigned and no report will ever attribute this work to a person.
 */
class ImportPlmTickets extends Command
{
    protected $signature = 'plm:import-tickets
        {--org=konvergenz : Organisation slug to import into}
        {--apply : Actually write. Without it, nothing changes}';

    protected $description = 'Import historic tickets and their conversations from the old PLM';

    /**
     * Impact, as the two systems name it.
     *
     * The only taxonomy that disagrees. Everything else — type, status,
     * urgency — matches word for word, because the defaults here were built
     * from this list in the first place.
     */
    private const IMPACTS = [
        'organization wide'       => 'High',
        'multiple users affected' => 'Medium',
        'single user affected'    => 'Low',
    ];

    /** Addresses that mean the message came from us. */
    private const OUR_DOMAINS = ['kns.co.ke', 'knscoke.onmicrosoft.com', 'konvergenz.co.ug'];

    private bool $apply = false;
    private int $orgId;
    private array $unmapped = [];
    private array $cache = [];

    /** Legacy ticket id => when it was resolved, for the trail written after. */
    private array $resolvedAt = [];

    public function handle(): int
    {
        $this->apply = (bool) $this->option('apply');

        $org = Organization::where('slug', $this->option('org'))->first();

        if (! $org) {
            $this->error("No organisation with slug '{$this->option('org')}'.");

            return self::FAILURE;
        }

        $this->orgId = $org->id;
        app()->instance('current.organization_id', $org->id);

        try {
            $this->legacy()->getPdo();
        } catch (\Throwable $e) {
            $this->error('Cannot reach the old PLM database: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->line($this->apply
            ? "Importing into <options=bold>{$org->name}</>."
            : "Rehearsing against <options=bold>{$org->name}</>. Nothing will be written — pass --apply to commit.");
        $this->newLine();

        $run = function () {
            $tickets = $this->importTickets();
            $messages = $this->importMessages();

            $this->summarise('Tickets', $tickets);
            $this->summarise('Conversations', $messages);
        };

        if ($this->apply) {
            DB::transaction($run);
        } else {
            DB::beginTransaction();

            try {
                $run();
            } finally {
                DB::rollBack();
            }
        }

        $this->reportUnmapped();

        return self::SUCCESS;
    }

    // ── The tickets ───────────────────────────────────────────────────────────

    private function importTickets(): array
    {
        $rows = $this->legacy()->table('mdl_tickets')->orderBy('id')->get();

        $made = 0;
        $updated = 0;
        $withContract = 0;
        $unattributed = 0;

        foreach ($rows as $row) {
            $account = PlmImportMap::resolve($this->orgId, 'mdl_accounts', $row->account);

            if (! $account) {
                /*
                 * Eighteen of them have no customer, no service level and no
                 * type — half-finished records rather than tickets. They are
                 * counted so the total reconciles, and left out so the queue
                 * is not seeded with rows nobody can act on or explain.
                 */
                $this->unmapped['held back']['Ticket ' . $row->id
                    . ' ("' . $this->shortSubject($row->subject) . '") has no customer'] = 1;
                $unattributed++;

                continue;
            }

            $contract = $this->contractFor($row->sla);

            if ($contract) {
                $withContract++;
            }

            $opened = $this->date($row->created_at) ?? now();

            $existing = PlmImportMap::resolve($this->orgId, 'mdl_tickets', $row->id);
            $ticket   = ($existing ? Ticket::find($existing->target_id) : null) ?? new Ticket();

            $impact  = $this->impact($row->impact);
            $urgency = $this->urgency($row->urgency);

            $ticket->forceFill(array_filter([
                'organization_id'     => $this->orgId,
                'reference'           => $ticket->reference
                    ?: Ticket::nextReference($this->orgId, (int) substr((string) $opened, 0, 4)),
                'account_id'          => $account->target_id,
                'account_contract_id' => $contract,
                'account_contact_id'  => $this->contactFor($row->from_email, $account->target_id),
                'subject'             => $this->text($row->subject) ?: '(no subject)',
                'body'                => $this->text($row->details) ?? $this->text($row->body) ?? '',
                'requester_name'      => $this->text($row->from_name),
                'requester_email'     => $this->text($row->from_email),
                'ticket_type_id'      => $this->type($row->type),
                'ticket_status_id'    => $this->status($row->status),
                'impact_id'           => $impact,
                'urgency_id'          => $urgency,
                'priority_id'         => PriorityMatrix::resolve($this->orgId, $impact, $urgency)?->id,
                'source'              => 'email',
                'opened_at'           => $opened,
                'created_at'          => $opened,
            ], fn ($v) => $v !== null));

            $this->stampOutcome($ticket, $row);

            $ticket->save();

            if ($existing) {
                $updated++;
            } else {
                $made++;

                PlmImportMap::create([
                    'organization_id' => $this->orgId,
                    'source_table'    => 'mdl_tickets',
                    'source_id'       => $row->id,
                    'target_type'     => Ticket::class,
                    'target_id'       => $ticket->id,
                    'source_snapshot' => ['subject' => $row->subject, 'account' => $row->account,
                                          'sla' => $row->sla, 'status' => $row->status],
                ]);
            }
        }

        $resolutions = $this->recordResolutions();

        return [
            'read' => $rows->count(), 'created' => $made, 'updated' => $updated,
            'with a contract' => $withContract, 'no customer' => $unattributed,
            'resolutions kept' => $resolutions,
        ];
    }

    /**
     * The resolution trail, and what somebody actually wrote.
     *
     * Two things the report needs and would otherwise lose.
     *
     * Volumes are counted from a resolved event rather than from the
     * `resolved_at` column, because reopening a ticket clears that column and
     * a month's figures must not change afterwards. Imported tickets never
     * pass through the status change that writes one, so without this a year
     * with two hundred and seventy-three closed tickets reports nought
     * resolved.
     *
     * And the resolution text itself — two hundred and forty of them, the
     * record of what was actually done — has nowhere to live on a ticket, so
     * it becomes an internal note. Internal rather than public: it is our
     * account of the fix, and there is nothing in the old data saying it was
     * ever sent to the customer.
     */
    private function recordResolutions(): int
    {
        if (! $this->resolvedAt) {
            return 0;
        }

        $kept = 0;

        foreach ($this->resolvedAt as $legacyId => $when) {
            $map = PlmImportMap::resolve($this->orgId, 'mdl_tickets', $legacyId);

            if (! $map) {
                continue;
            }

            $already = TicketEvent::where('support_ticket_id', $map->target_id)
                ->where('event', TicketEvent::RESOLVED)->exists();

            if (! $already) {
                TicketEvent::create([
                    'support_ticket_id' => $map->target_id,
                    'event'       => TicketEvent::RESOLVED,
                    'to_value'    => 'Resolved',
                    'automatic'   => true,
                    'occurred_at' => $when,
                ]);
            }

            foreach ($this->legacy()->table('mdl_tickets_resolutions')
                ->where('ticket', $legacyId)->orderBy('id')->get() as $row) {
                $body = $this->text($row->resolution);

                if (! $body) {
                    continue;
                }

                $exists = TicketMessage::where('support_ticket_id', $map->target_id)
                    ->where('visibility', TicketMessage::VISIBILITY_INTERNAL)
                    ->where('body', $body)->exists();

                if ($exists) {
                    continue;
                }

                TicketMessage::create([
                    'support_ticket_id' => $map->target_id,
                    'visibility'    => TicketMessage::VISIBILITY_INTERNAL,
                    'body'          => $body,
                    'author_name'   => 'Imported from the old system',
                    'source'        => 'agent',
                    'is_from_agent' => true,
                    'created_at'    => $this->date($row->created_at) ?? $when,
                    'updated_at'    => $this->date($row->created_at) ?? $when,
                ]);

                $kept++;
            }
        }

        return $kept;
    }

    /**
     * When it was answered and when it was fixed, where either is a fact.
     *
     * The resolution date comes from the resolution somebody wrote, which is
     * the only record of it — and 94 of the 324 finished tickets have none, so
     * those close without a date rather than borrowing the last email's. The
     * date a conversation stopped is not the date somebody fixed something,
     * and a resolution time computed from it would look exactly like a real
     * one.
     */
    private function stampOutcome(Ticket $ticket, object $row): void
    {
        $resolvedAt = $this->legacy()->table('mdl_tickets_resolutions')
            ->where('ticket', $row->id)->min('created_at');

        $status = TicketStatus::find($ticket->ticket_status_id);

        if ($status?->isTerminal() && $resolvedAt) {
            $ticket->forceFill([
                'resolved_at' => $this->date($resolvedAt),
                'closed_at'   => $status->state === TicketStatus::STATE_CLOSED
                    ? $this->date($resolvedAt) : null,
            ]);

            $this->resolvedAt[$row->id] = $this->date($resolvedAt);
        }

        /*
         * The first reply from one of our addresses. A recorded fact rather
         * than a derived one — somebody wrote it, and it is the only first
         * response this history will ever have.
         */
        $first = $this->legacy()->table('mdl_tickets_emails')
            ->where('ticket', $row->id)
            ->orderBy('id')
            ->get(['from_email', 'created_at'])
            ->first(fn ($mail) => in_array($this->domain($mail->from_email), self::OUR_DOMAINS, true));

        if ($first) {
            $ticket->forceFill(['first_response_at' => $this->date($first->created_at)]);
        }
    }

    // ── The conversations ─────────────────────────────────────────────────────

    private function importMessages(): array
    {
        /*
         * Counts the mail, not every message.
         *
         * This guard exists so a second run does not append the whole
         * conversation again — messages have no stable identity to match on.
         * But it used to count *any* message on an imported ticket, and the
         * resolution notes written earlier in this same pass are messages. So
         * on a re-import it saw 224 internal notes, concluded the mail was
         * already in, and skipped 2,409 emails without a word.
         */
        $existing = TicketMessage::whereIn('support_ticket_id',
            PlmImportMap::where('source_table', 'mdl_tickets')->pluck('target_id'))
            ->where('source', 'email')
            ->count();

        if ($existing > 0) {
            /*
             * Messages have no stable identity to match on once written, so a
             * second run would append the whole conversation again. Skipped
             * rather than duplicated: re-running the import must be safe.
             */
            return ['already imported' => $existing, 'read' => 0, 'created' => 0];
        }

        $rows = $this->legacy()->table('mdl_tickets_emails')->orderBy('id')->get();

        $made = 0;
        $orphaned = 0;
        $lostTickets = [];
        $rows = $rows->chunk(500);

        foreach ($rows as $chunk) {
            $batch = [];

            foreach ($chunk as $mail) {
                $ticket = PlmImportMap::resolve($this->orgId, 'mdl_tickets', $mail->ticket);

                if (! $ticket) {
                    /*
                     * Its ticket is gone. The old system deleted tickets and
                     * left their correspondence behind, so two thirds of this
                     * table hangs off rows that no longer exist — the same
                     * habit of writing things nothing can read that runs
                     * through the system being replaced.
                     *
                     * Not resurrected. Building shell tickets out of orphaned
                     * subject lines would put a history in front of a customer
                     * that nobody can stand behind.
                     */
                    $orphaned++;
                    $lostTickets[$mail->ticket] = true;

                    continue;
                }

                $fromUs = in_array($this->domain($mail->from_email), self::OUR_DOMAINS, true);

                $batch[] = [
                    'support_ticket_id' => $ticket->target_id,
                    'visibility'   => TicketMessage::VISIBILITY_PUBLIC,
                    'body'         => $this->text($mail->body) ?? $this->text($mail->body_preview) ?? '',
                    'author_name'  => $this->text($mail->from_name),
                    'author_email' => $this->text($mail->from_email),
                    'source'       => 'email',
                    // Read off the address, because it decides whether a reply
                    // counts as ours in every figure built from these later.
                    'is_from_agent' => $fromUs,
                    'created_at'   => $this->date($mail->created_at) ?? now(),
                    'updated_at'   => $this->date($mail->created_at) ?? now(),
                ];

                $made++;
            }

            if ($batch) {
                TicketMessage::insert($batch);
            }
        }

        if ($orphaned > 0) {
            $this->unmapped['held back'][
                number_format($orphaned) . ' emails belong to ' . number_format(count($lostTickets))
                . ' tickets that were deleted from the old system — there is nothing to attach them to'
            ] = 1;
        }

        return ['read' => $made + $orphaned, 'created' => $made, 'orphaned' => $orphaned];
    }

    // ── Resolving ─────────────────────────────────────────────────────────────

    /**
     * The contract a ticket was worked under.
     *
     * The old ticket table has no contract, which is why nobody could ever
     * answer "how did we do on the network contract last quarter". It has an
     * SLA package, and the package names its contract — so the link exists,
     * one join away, and has been sitting there unused.
     */
    private function contractFor(mixed $slaId): ?int
    {
        if (! $slaId || ! is_numeric($slaId)) {
            return null;
        }

        $legacyContract = $this->legacy()->table('mdl_contract_management_sla')
            ->where('id', (int) $slaId)->value('contract');

        $map = PlmImportMap::resolve($this->orgId, 'mdl_contract_management', $legacyContract);

        if (! $map) {
            $this->unmapped['contract']["SLA package {$slaId}"] =
                ($this->unmapped['contract']["SLA package {$slaId}"] ?? 0) + 1;

            return null;
        }

        return AccountContract::whereKey($map->target_id)->value('id');
    }

    /** The person who raised it, if they are on the contact list. */
    private function contactFor(?string $email, int $accountId): ?int
    {
        $email = $this->text($email);

        if (! $email) {
            return null;
        }

        return AccountContact::where('account_id', $accountId)
            ->whereRaw('lower(email) = ?', [mb_strtolower($email)])
            ->value('id');
    }

    private function type(mixed $id): ?int
    {
        return $this->match(TicketType::class, $this->legacyName('mdl_tickets_types', $id), 'ticket type');
    }

    private function status(mixed $id): ?int
    {
        return $this->match(TicketStatus::class, $this->legacyName('mdl_tickets_status', $id), 'status')
            ?? TicketStatus::where('organization_id', $this->orgId)->where('is_default', true)->value('id');
    }

    private function urgency(mixed $id): ?int
    {
        return $this->match(TicketUrgency::class, $this->legacyName('mdl_tickets_urgency', $id), 'urgency');
    }

    private function impact(mixed $id): ?int
    {
        $name = mb_strtolower((string) $this->legacyName('mdl_tickets_impact', $id));

        return $this->match(TicketImpact::class, self::IMPACTS[$name] ?? null, 'impact');
    }

    private function match(string $model, ?string $name, string $what): ?int
    {
        if (! $name) {
            return null;
        }

        $key = $model . '|' . mb_strtolower($name);

        if (! array_key_exists($key, $this->cache)) {
            $this->cache[$key] = $model::where('organization_id', $this->orgId)
                ->whereRaw('lower(name) = ?', [mb_strtolower(trim($name))])
                ->value('id');
        }

        if (! $this->cache[$key]) {
            $this->unmapped[$what][$name] = ($this->unmapped[$what][$name] ?? 0) + 1;
        }

        return $this->cache[$key];
    }

    // ── Shared ────────────────────────────────────────────────────────────────

    private function legacyName(string $table, mixed $id): ?string
    {
        if (! $id || ! is_numeric($id)) {
            return null;
        }

        $key = "{$table}|{$id}";

        return $this->cache[$key] ??= ($this->legacy()->table($table)
            ->where('id', (int) $id)->value('name') ?: null);
    }

    /** A subject, short enough to read in a summary line. */
    private function shortSubject(?string $value): string
    {
        $value = trim((string) $value);

        return mb_strlen($value) > 48 ? mb_substr($value, 0, 48) . '…' : ($value ?: '(no subject)');
    }

    private function domain(?string $email): ?string
    {
        $email = $this->text($email);

        return $email && str_contains($email, '@')
            ? mb_strtolower(trim(substr(strrchr($email, '@'), 1)))
            : null;
    }

    private function text(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return ($value === '' || mb_strtoupper($value) === 'NULL') ? null : $value;
    }

    private function date(mixed $value): ?string
    {
        $value = $this->text($value);

        return ($value === null || str_starts_with($value, '0000')) ? null : $value;
    }

    private function legacy(): \Illuminate\Database\ConnectionInterface
    {
        return DB::connection('plm');
    }

    private function summarise(string $label, array $counts): void
    {
        $parts = [];

        foreach ($counts as $key => $value) {
            $parts[] = "{$value} {$key}";
        }

        $this->line("  <options=bold>{$label}</>: " . implode(', ', $parts));
    }

    private function reportUnmapped(): void
    {
        if (! $this->unmapped) {
            $this->newLine();
            $this->info('Everything mapped. Nothing was left unset.');

            return;
        }

        foreach ($this->unmapped as $what => $values) {
            $this->newLine();
            $this->warn($what === 'held back' ? 'Held back:' : "No {$what} here by that name — left unset:");

            arsort($values);

            foreach (array_slice($values, 0, 20, true) as $value => $count) {
                $this->line($what === 'held back' ? "  · {$value}" : "  · {$value} — {$count}");
            }

            if (count($values) > 20) {
                $this->line('  · … and ' . (count($values) - 20) . ' more');
            }
        }
    }
}

