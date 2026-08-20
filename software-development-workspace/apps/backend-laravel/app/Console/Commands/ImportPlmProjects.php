<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\Pipeline\Opportunity;
use App\Models\Pipeline\OpportunityPhase;
use App\Models\PlmImportMap;
use App\Models\Project\Project;
use App\Models\Project\ProjectComment;
use App\Models\Task\Task;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bring the delivery side across from the old PLM.
 *
 * Leads, opportunities, projects, tasks and the sixty-three thousand comments
 * people wrote on them. This is where the company's work actually happened —
 * the tickets are what customers asked for, and this is what was done about it.
 *
 * Imported in dependency order in one command rather than five, because each
 * step resolves against the one before it: an opportunity needs its lead, a
 * project its opportunity, a task its project. Five commands would mean five
 * chances to run them out of order.
 *
 * ── Nothing is raised as work ──
 *
 * Same rule as the tickets. These are records of what happened, not new jobs:
 * no notifications, no assignment mail, no recurrence schedules started. A
 * completed task from 2024 must not appear in somebody's queue tomorrow.
 *
 * ── People who were not imported ──
 *
 * Only Konvergenz staff came across, so a task assigned to somebody at Tiberbu
 * has no assignee here. Left unset and counted rather than pointed at a
 * plausible colleague: an owner is read as a fact by every board and every
 * workload figure.
 */
class ImportPlmProjects extends Command
{
    protected $signature = 'plm:import-projects
        {--org=konvergenz : Organisation slug to import into}
        {--apply : Actually write. Without it, nothing changes}';

    protected $description = 'Import leads, opportunities, projects, tasks and their comments';

    /** Legacy task states, as ours name them. */
    private const TASK_STATUS = [
        'completed' => 'completed',
        'ongoing'   => 'in_progress',
        'pending'   => 'todo',
    ];

    /**
     * Legacy project states.
     *
     * "Support" has no equivalent here and is the interesting one: seventeen
     * projects were handed over and are being supported, which our vocabulary
     * calls delivered and nothing more. They are mapped to delivered and
     * counted, because a project in support is finished as a project.
     */
    private const PROJECT_STATUS = [
        'pending'   => 'kickoff',
        'ongoing'   => 'in_flight',
        'on hold'   => 'on_hold',
        'completed' => 'delivered',
        'support'   => 'delivered',
    ];

    private const PRIORITY = ['high' => 'high', 'medium' => 'medium', 'low' => 'low'];

    private bool $apply = false;
    private int $orgId;
    private array $unmapped = [];
    private array $cache = [];

    /** Tickets whose link a task has already claimed. */
    private array $ticketTaken = [];

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
            $this->summarise('Opportunities', $this->importOpportunities());
            $this->summarise('Projects', $this->importProjects());
            $this->summarise('Tasks', $this->importTasks());
            $this->summarise('Comments', $this->importComments());
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

    // ── Opportunities ─────────────────────────────────────────────────────────

    private function importOpportunities(): array
    {
        $rows = $this->legacy()->table('mdl_projects_opportunities')->orderBy('id')->get();

        $made = 0;
        $updated = 0;
        $noAccount = 0;

        foreach ($rows as $row) {
            $account = PlmImportMap::resolve($this->orgId, 'mdl_accounts', $row->account);

            if (! $account) {
                $noAccount++;

                continue;
            }

            [$record, $isNew] = $this->upsert('mdl_projects_opportunities', $row->id, Opportunity::class, [
                'organization_id'      => $this->orgId,
                'account_id'           => $account->target_id,
                'name'                 => $this->text($row->name) ?: 'Untitled opportunity',
                'description'          => $this->text($row->description),
                'opportunity_type'     => $this->legacyName('mdl_opportunity_types', $row->opportunity_type),
                'practice'             => $this->legacyName('mdl_bu_practices', $row->practice),
                'amount'               => is_numeric($row->budget) ? $row->budget : null,
                'expected_close_date'  => $this->date($row->estimated_close_date),
                'opportunity_phase_id' => $this->phase($row->phase),
                'presale_lead_id'      => $this->employee($row->presale_lead),
                'account_weight'       => $this->flag($row->account_weight),
                'knows_procurement'    => $this->flag($row->know_procurement),
                'deal_registered'      => $this->flag($row->deal_registered),
                'compliance_pressure'  => $this->flag($row->compliance_pressure),
                'oem_pressure'         => $this->flag($row->oem_pressure),
                'prior_deal_closed'    => $this->flag($row->prior_deal_clossed),
                'is_committed'         => $this->flag($row->commited),
                'committed_year'       => is_numeric($row->year_commited) ? (int) $row->year_commited : null,
                'committed_quarter'    => $this->text($row->commited_quater),
                'discuss_weekly'       => $this->flag($row->discuss_in_weekly_meetings),
                'gap_analysis'         => $this->text($row->gap_analysis_comment),
                'created_at'           => $this->date($row->created_at) ?? now(),
            ], ['name' => $row->name, 'account' => $row->account, 'phase' => $row->phase]);

            $isNew ? $made++ : $updated++;
        }

        return ['read' => $rows->count(), 'created' => $made, 'updated' => $updated,
                'no customer' => $noAccount];
    }

    // ── Projects ──────────────────────────────────────────────────────────────

    private function importProjects(): array
    {
        $rows = $this->legacy()->table('mdl_projects')->orderBy('id')->get();

        $made = 0;
        $updated = 0;
        $inSupport = 0;

        foreach ($rows as $row) {
            $account = PlmImportMap::resolve($this->orgId, 'mdl_accounts', $row->account);
            $opportunity = PlmImportMap::resolve($this->orgId, 'mdl_projects_opportunities', $row->opportunity);

            if (mb_strtolower(trim((string) $row->status)) === 'support') {
                $inSupport++;
            }

            [, $isNew] = $this->upsert('mdl_projects', $row->id, Project::class, [
                'organization_id' => $this->orgId,
                'account_id'      => $account?->target_id,
                'opportunity_id'  => $opportunity?->target_id,
                'name'            => $this->text($row->name) ?: 'Untitled project',
                'description'     => $this->text($row->description),
                'status'          => $this->projectStatus($row->status),
                'started_on'      => $this->date($row->start_date),
                'deadline'        => $this->date($row->end_date),
                'project_lead_id' => $this->employee($row->project_manager),
                'created_at'      => $this->date($row->created_at) ?? now(),
            ], ['name' => $row->name, 'status' => $row->status, 'account' => $row->account]);

            $isNew ? $made++ : $updated++;
        }

        return ['read' => $rows->count(), 'created' => $made, 'updated' => $updated,
                'in support' => $inSupport];
    }

    private function projectStatus(?string $status): string
    {
        $name = mb_strtolower(trim((string) $status));

        if (! isset(self::PROJECT_STATUS[$name])) {
            $this->unmapped['project status'][$status ?: '(blank)'] =
                ($this->unmapped['project status'][$status ?: '(blank)'] ?? 0) + 1;

            return 'kickoff';
        }

        return self::PROJECT_STATUS[$name];
    }

    // ── Tasks ─────────────────────────────────────────────────────────────────

    private function importTasks(): array
    {
        $rows = $this->legacy()->table('mdl_tasks')->orderBy('id')->get();

        $made = 0;
        $updated = 0;
        $unowned = 0;
        $standalone = 0;
        $sharedSource = 0;

        foreach ($rows as $row) {
            $project     = PlmImportMap::resolve($this->orgId, 'mdl_projects', $row->project);
            $opportunity = PlmImportMap::resolve($this->orgId, 'mdl_projects_opportunities', $row->opportunity);
            $account     = PlmImportMap::resolve($this->orgId, 'mdl_accounts', $row->account);
            $assignee    = $this->employee($row->assigned_to);

            if (! $assignee) {
                $unowned++;
            }

            /*
             * One task may carry the link, because the schema holds a source
             * to one task only — that constraint stops the ticket-to-task
             * generator producing duplicates, and it is worth more than the
             * extra links. The earliest task keeps it and the rest are
             * counted, so the limit is stated rather than discovered.
             */
            $fromTicket = $this->ticketFor($row->ticket);

            if ($fromTicket && isset($this->ticketTaken[$fromTicket])) {
                $sharedSource++;
                $fromTicket = null;
            } elseif ($fromTicket) {
                $this->ticketTaken[$fromTicket] = true;
            }

            if (! $project) {
                /*
                 * Three quarters of them belong to no project. They hang off an
                 * opportunity, a meeting, a ticket or nothing at all — the old
                 * system used one task list for everything, and the schema here
                 * was built expecting that.
                 */
                $standalone++;
            }

            [, $isNew] = $this->upsert('mdl_tasks', $row->id, Task::class, [
                'organization_id' => $this->orgId,
                'project_id'      => $project?->target_id,
                'opportunity_id'  => $opportunity?->target_id,
                'account_id'      => $account?->target_id,
                'title'           => $this->text($row->name) ?: 'Untitled task',
                'description'     => $this->text($row->details),
                'status'          => $this->taskStatus($row->status),
                'priority'        => self::PRIORITY[mb_strtolower(trim((string) $row->priority))] ?? 'medium',
                'deadline'        => $this->date($row->due_date),
                'started_on'      => $this->date($row->start_date ?? $row->starting_date),
                'completed_at'    => $this->completedAt($row),
                'assigned_to'     => $assignee,
                'effort_estimate_days' => $this->effortDays($row->work_effort_estimate),
                'created_at'      => $this->date($row->created_at) ?? now(),
                /*
                 * Where a task came out of a ticket, that link is worth
                 * keeping — "what did we actually do about this" is the
                 * question a ticket alone cannot answer.
                 */
                'generated_from_type' => $fromTicket ? 'support_ticket' : null,
                'generated_from_id'   => $fromTicket,
            ], ['name' => $row->name, 'project' => $row->project, 'status' => $row->status]);

            $isNew ? $made++ : $updated++;
        }

        if ($sharedSource > 0) {
            $this->unmapped['ticket link'][
                "{$sharedSource} tasks share a source ticket with an earlier task — "
                . 'the schema records a source against one task only'
            ] = 1;
        }

        return ['read' => $rows->count(), 'created' => $made, 'updated' => $updated,
                'no project' => $standalone, 'nobody to assign' => $unowned];
    }

    private function taskStatus(?string $status): string
    {
        $name = mb_strtolower(trim((string) $status));

        if (! isset(self::TASK_STATUS[$name])) {
            $this->unmapped['task status'][$status ?: '(blank)'] =
                ($this->unmapped['task status'][$status ?: '(blank)'] ?? 0) + 1;

            return 'todo';
        }

        return self::TASK_STATUS[$name];
    }

    /** Completed only where the old system says so, and dated only if it knows. */
    private function completedAt(object $row): ?string
    {
        return $this->taskStatus($row->status) === 'completed'
            ? $this->date($row->end_date)
            : null;
    }

    // ── What people wrote ─────────────────────────────────────────────────────

    private function importComments(): array
    {
        $already = ProjectComment::where('organization_id', $this->orgId)->count();

        if ($already > 0) {
            // No stable identity once written, so a second run would repeat
            // the whole discussion. Skipped rather than duplicated.
            return ['already imported' => $already, 'read' => 0, 'created' => 0];
        }

        $made = 0;
        $orphaned = 0;

        $this->legacy()->table('mdl_tasks_comments')->orderBy('id')
            ->chunk(1000, function ($chunk) use (&$made, &$orphaned) {
                $batch = [];

                foreach ($chunk as $row) {
                    $task = PlmImportMap::resolve($this->orgId, 'mdl_tasks', $row->task);
                    $body = $this->text($row->comment);

                    if (! $task || ! $body) {
                        $orphaned++;

                        continue;
                    }

                    $batch[] = [
                        'organization_id' => $this->orgId,
                        'task_id'    => $task->target_id,
                        'body'       => $body,
                        'employee_id' => $this->employee($row->author),
                        'created_at' => $this->date($row->created_at) ?? now(),
                        'updated_at' => $this->date($row->created_at) ?? now(),
                    ];

                    $made++;
                }

                if ($batch) {
                    ProjectComment::insert($batch);
                }
            });

        return ['read' => $made + $orphaned, 'created' => $made, 'orphaned' => $orphaned];
    }

    // ── Resolving ─────────────────────────────────────────────────────────────

    /**
     * An employee, if they came across.
     *
     * Only Konvergenz staff were imported, so anybody at Tiberbu, Visibility or
     * Canadatel resolves to nothing. Left unset rather than guessed: an owner
     * is read as fact by every board and every workload figure.
     */
    private function employee(mixed $legacyId): ?int
    {
        if (! $legacyId || ! is_numeric($legacyId)) {
            return null;
        }

        $key = "emp|{$legacyId}";

        if (! array_key_exists($key, $this->cache)) {
            $map = PlmImportMap::resolve($this->orgId, 'mdl_hr_employees', $legacyId);

            $this->cache[$key] = $map
                ? Employee::whereKey($map->target_id)->value('id')
                : null;
        }

        return $this->cache[$key];
    }

    private function ticketFor(mixed $legacyId): ?int
    {
        if (! $legacyId || ! is_numeric($legacyId)) {
            return null;
        }

        $key = "tkt|{$legacyId}";

        return $this->cache[$key] ??= PlmImportMap::resolve($this->orgId, 'mdl_tickets', $legacyId)?->target_id;
    }

    private function phase(mixed $legacyId): ?int
    {
        $name = $this->legacyName('mdl_projects_opportunity_phases', $legacyId);

        if (! $name) {
            return null;
        }

        $id = OpportunityPhase::where('organization_id', $this->orgId)
            ->whereRaw('lower(trim(name)) = ?', [mb_strtolower(trim($name))])
            ->value('id');

        if (! $id) {
            $this->unmapped['opportunity phase'][$name] =
                ($this->unmapped['opportunity phase'][$name] ?? 0) + 1;
        }

        return $id;
    }

    /**
     * @return array{0: object, 1: bool}
     */
    private function upsert(string $table, int $sourceId, string $model, array $attributes, array $snapshot): array
    {
        $existing = PlmImportMap::resolve($this->orgId, $table, $sourceId);

        $record = ($existing ? $model::find($existing->target_id) : null) ?? new $model();
        $isNew  = ! $record->exists;

        $record->forceFill(array_filter($attributes, fn ($v) => $v !== null))->save();

        if ($isNew) {
            PlmImportMap::updateOrCreate(
                ['organization_id' => $this->orgId, 'source_table' => $table, 'source_id' => $sourceId],
                ['target_type' => $model, 'target_id' => $record->id, 'source_snapshot' => $snapshot],
            );
        }

        return [$record, $isNew];
    }

    // ── Shared ────────────────────────────────────────────────────────────────

    /**
     * Effort, converted from minutes to working days.
     *
     * The old column is a string of minutes — 480 is a day, 2400 a week, and
     * the commonest value is 60. Copying the number straight across would have
     * put "262800 days" on a task that took a fortnight, and the figure would
     * have looked deliberate.
     *
     * Negatives exist and are discarded: a task cannot take minus a week, and
     * whatever they meant is not recoverable. One estimate is large enough to
     * overflow the column even in days; it is dropped and named rather than
     * silently rounded down to something believable.
     */
    private function effortDays(mixed $minutes): ?float
    {
        if (! is_numeric($minutes) || (int) $minutes <= 0) {
            return null;
        }

        $days = round(((int) $minutes) / 480, 2);

        if ($days > 9999.99) {
            $this->unmapped['effort estimate'][
                number_format((int) $minutes) . ' minutes is beyond anything this column can hold'
            ] = ($this->unmapped['effort estimate'][number_format((int) $minutes) . ' minutes is beyond anything this column can hold'] ?? 0) + 1;

            return null;
        }

        return $days;
    }

    /** The old system stored yes/no as a word, a number, or nothing. */
    private function flag(mixed $value): ?bool
    {
        $value = mb_strtolower(trim((string) ($value ?? '')));

        return match (true) {
            in_array($value, ['1', 'yes', 'true'], true)  => true,
            in_array($value, ['0', 'no', 'false'], true)  => false,
            default => null,
        };
    }

    private function legacyName(string $table, mixed $id): ?string
    {
        if (! $id || ! is_numeric($id)) {
            return null;
        }

        $key = "{$table}|{$id}";

        if (! array_key_exists($key, $this->cache)) {
            try {
                $this->cache[$key] = $this->legacy()->table($table)
                    ->where('id', (int) $id)->value('name') ?: null;
            } catch (\Throwable) {
                // A lookup table that does not exist in this dump.
                $this->cache[$key] = null;
            }
        }

        return $this->cache[$key];
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

            /*
             * Two kinds of thing end up here: a name we could not match, and a
             * fact the schema would not hold. They read as nonsense under one
             * another's heading.
             */
            $limits = in_array($what, ['effort estimate', 'ticket link'], true);

            $this->warn($limits
                ? 'Could not be carried across:'
                : "No {$what} here by that name — left unset:");

            arsort($values);

            foreach (array_slice($values, 0, 15, true) as $value => $count) {
                $this->line($limits ? "  · {$value}" : "  · {$value} — {$count}");
            }
        }
    }
}
