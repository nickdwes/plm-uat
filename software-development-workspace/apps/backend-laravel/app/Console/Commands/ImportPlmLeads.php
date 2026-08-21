<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\Pipeline\Lead;
use App\Models\Pipeline\Opportunity;
use App\Models\PlmImportMap;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bring the leads across from the old PLM, and join them to what they became.
 *
 * A hundred and sixty-two of them, and the reason they matter is the second
 * half of that sentence: a hundred and thirty-three turned into opportunities
 * and seventeen into projects. Without the leads, the pipeline starts at the
 * opportunity and nobody can ask the question the pipeline exists to answer —
 * how much of what we chase we win, and how much of it we never should have
 * chased.
 *
 * The opportunities were imported first and their `lead_id` left empty because
 * there was nothing to point at. This fills it in.
 */
class ImportPlmLeads extends Command
{
    protected $signature = 'plm:import-leads
        {--org=konvergenz : Organisation slug to import into}
        {--apply : Actually write. Without it, nothing changes}';

    protected $description = 'Import leads and link them to the opportunities they became';

    /**
     * Where a lead got to, in this system's words.
     *
     * Two of the old states say the same thing — attached to an opportunity
     * and attached to a project are both a lead that turned into work, which
     * is what converted means. "Cold" has no equivalent: it meant a lead that
     * went quiet rather than one judged a poor fit, and calling it unqualified
     * is the closest available and not the same. Three of them, and the
     * summary says so.
     */
    private const STATUSES = [
        'new'                        => 'new',
        'contacted'                  => 'contacted',
        'qualified'                  => 'qualified',
        'attached to an opportunity' => 'converted',
        'attached to a project'      => 'converted',
        'lost'                       => 'unqualified',
        'cold'                       => 'unqualified',
    ];

    private bool $apply = false;
    private int $orgId;
    private array $unmapped = [];
    private array $cache = [];

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
            $this->summarise('Leads', $this->importLeads());
            $this->summarise('Linked to opportunities', $this->linkOpportunities());
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

    // ── The leads ─────────────────────────────────────────────────────────────

    private function importLeads(): array
    {
        $rows = $this->legacy()->table('mdl_projects_leads')->orderBy('id')->get();

        $made = 0;
        $updated = 0;
        $noAccount = 0;
        $noOwner = 0;

        foreach ($rows as $row) {
            $account = PlmImportMap::resolve($this->orgId, 'mdl_accounts', $row->account);

            if (! $account) {
                $this->unmapped['held back']["Lead {$row->id} has no customer"] = 1;
                $noAccount++;

                continue;
            }

            /*
             * Failing an owner, whoever raised it. One lead has no owner and
             * does have an author, and an owned lead is one somebody can be
             * asked about.
             */
            $owner = $this->employee($row->owner) ?? $this->employee($row->author);

            if (! $owner) {
                $noOwner++;
            }

            $existing = PlmImportMap::resolve($this->orgId, 'mdl_projects_leads', $row->id);
            $lead     = ($existing ? Lead::find($existing->target_id) : null) ?? new Lead();

            $lead->forceFill(array_filter([
                'organization_id' => $this->orgId,
                'account_id'      => $account->target_id,
                'name'            => $this->text($row->name) ?: 'Untitled lead',
                /*
                 * The old system had one description field; this has two, and
                 * they are not interchangeable. `summary` is labelled "one-line
                 * summary, shown on the board card" and holds 255 characters;
                 * the description runs to two and a half thousand. So the text
                 * goes where there is room for it and the card gets the first
                 * sentence of it.
                 */
                'problem_statement' => $this->plain($row->description),
                'summary'           => $this->oneLine($this->plain($row->description)),
                'status'          => $this->status($row->status),
                /*
                 * The column is required and the old system had no such field,
                 * so everything arrives in the middle. Recorded here rather
                 * than left to look like a judgement somebody made: a board
                 * where every lead is medium priority is a board where the
                 * column means nothing.
                 */
                'priority'        => 'medium',
                'owner_id'        => $owner,
                'created_by'      => $this->userBehind($row->author),
                'created_at'      => $this->date($row->created_at) ?? now(),
            ], fn ($v) => $v !== null));

            $lead->save();

            if ($existing) {
                $updated++;
            } else {
                $made++;

                PlmImportMap::create([
                    'organization_id' => $this->orgId, 'source_table' => 'mdl_projects_leads',
                    'source_id' => $row->id, 'target_type' => Lead::class, 'target_id' => $lead->id,
                    'source_snapshot' => ['name' => $row->name, 'status' => $row->status,
                                          'account' => $row->account, 'opportunity' => $row->opportunity],
                ]);
            }
        }

        if ($noOwner > 0) {
            $this->unmapped['held back'][
                "{$noOwner} leads are owned by somebody who was not imported, and have no owner here"
            ] = 1;
        }

        return ['read' => $rows->count(), 'created' => $made, 'updated' => $updated,
                'no customer' => $noAccount, 'nobody to own them' => $noOwner];
    }

    // ── What they became ──────────────────────────────────────────────────────

    /**
     * Join each opportunity back to the lead it grew out of.
     *
     * Both sides recorded the link, and neither is complete: the lead names
     * its opportunity and the opportunity names its lead, and there are rows
     * where only one of the two was ever filled in. Reading both and taking
     * whichever resolves recovers more of the pipeline than trusting either.
     *
     * Where they disagree the opportunity's own answer wins, because that is
     * the record somebody was looking at when they created it.
     */
    private function linkOpportunities(): array
    {
        $linked = 0;
        $disagreed = 0;
        $unresolved = 0;

        $rows = $this->legacy()->table('mdl_projects_opportunities')
            ->orderBy('id')->get(['id', 'lead']);

        $fromOpportunity = [];

        foreach ($rows as $row) {
            if ($row->lead) {
                $fromOpportunity[$row->id] = (int) $row->lead;
            }
        }

        $fromLead = [];

        foreach ($this->legacy()->table('mdl_projects_leads')
            ->whereNotNull('opportunity')->where('opportunity', '<>', 0)
            ->get(['id', 'opportunity']) as $row) {
            $fromLead[(int) $row->opportunity] = (int) $row->id;
        }

        foreach (array_keys($fromOpportunity + $fromLead) as $legacyOpportunity) {
            $theirs = $fromOpportunity[$legacyOpportunity] ?? null;
            $ours   = $fromLead[$legacyOpportunity] ?? null;

            if ($theirs && $ours && $theirs !== $ours) {
                $disagreed++;
            }

            $legacyLead = $theirs ?? $ours;

            $opportunity = PlmImportMap::resolve($this->orgId, 'mdl_projects_opportunities', $legacyOpportunity);
            $lead        = PlmImportMap::resolve($this->orgId, 'mdl_projects_leads', $legacyLead);

            if (! $opportunity || ! $lead) {
                $unresolved++;

                continue;
            }

            Opportunity::whereKey($opportunity->target_id)->update(['lead_id' => $lead->target_id]);
            $linked++;
        }

        if ($disagreed > 0) {
            $this->unmapped['held back'][
                "{$disagreed} opportunities and leads disagree about which belongs to which — "
                . "the opportunity's own record was used"
            ] = 1;
        }

        return ['pairs seen' => count($fromOpportunity + $fromLead), 'linked' => $linked,
                'one side missing' => $unresolved];
    }

    // ── Resolving ─────────────────────────────────────────────────────────────

    private function status(?string $legacy): string
    {
        $name = mb_strtolower(trim((string) $legacy));

        if (! isset(self::STATUSES[$name])) {
            $this->unmapped['lead status'][$legacy ?: '(blank)'] =
                ($this->unmapped['lead status'][$legacy ?: '(blank)'] ?? 0) + 1;

            return 'new';
        }

        if ($name === 'cold') {
            $this->unmapped['held back']['"Cold" leads are recorded as unqualified — '
                . 'this system has no word for a lead that simply went quiet'] = 1;
        }

        return self::STATUSES[$name];
    }

    private function employee(mixed $legacyId): ?int
    {
        if (! $legacyId || ! is_numeric($legacyId)) {
            return null;
        }

        $key = "emp|{$legacyId}";

        if (! array_key_exists($key, $this->cache)) {
            $map = PlmImportMap::resolve($this->orgId, 'mdl_hr_employees', $legacyId);

            $this->cache[$key] = $map ? Employee::whereKey($map->target_id)->value('id') : null;
        }

        return $this->cache[$key];
    }

    /**
     * The first sentence, for the board card.
     *
     * Cut at a sentence end where there is one within reach, and at a word
     * otherwise — a card that stops mid-word reads as broken rather than as
     * abbreviated.
     */
    private function oneLine(?string $text, int $limit = 240): ?string
    {
        if ($text === null) {
            return null;
        }

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $head = mb_substr($text, 0, $limit);
        $stop = max(mb_strrpos($head, '. ') ?: 0, mb_strrpos($head, '? ') ?: 0);

        if ($stop > $limit / 3) {
            return mb_substr($head, 0, $stop + 1);
        }

        $space = mb_strrpos($head, ' ');

        return rtrim($space ? mb_substr($head, 0, $space) : $head) . '…';
    }

    /**
     * The login behind an employee, for the columns that want a user.
     *
     * `created_by` points at users while everything the old system recorded
     * points at employees, so the two are joined through the employee's own
     * account rather than by hoping the ids line up — they do not.
     */
    private function userBehind(mixed $legacyEmployeeId): ?int
    {
        $employee = $this->employee($legacyEmployeeId);

        if (! $employee) {
            return null;
        }

        $key = "user-of|{$employee}";

        return $this->cache[$key] ??= Employee::whereKey($employee)->value('user_id');
    }

    /** A description as a sentence: the old editor wrote HTML into this too. */
    private function plain(mixed $value): ?string
    {
        $raw = $this->text($value);

        if ($raw === null) {
            return null;
        }

        $spaced = preg_replace('#</(p|div|li|br|h[1-6])\s*>|<br\s*/?>#i', ' ', $raw) ?? $raw;
        $text   = html_entity_decode(strip_tags($spaced), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $this->text(preg_replace('/\s+/u', ' ', $text));
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
            $this->warn($what === 'held back' ? 'Worth knowing:' : "No {$what} here by that name:");

            arsort($values);

            foreach (array_slice($values, 0, 15, true) as $value => $count) {
                $this->line($what === 'held back' ? "  · {$value}" : "  · {$value} — {$count}");
            }
        }
    }
}
