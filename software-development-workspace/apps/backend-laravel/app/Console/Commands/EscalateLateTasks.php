<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Task\TaskEscalationLadder;
use Illuminate\Console\Command;

/**
 * Tell somebody about work that has gone past its marks.
 *
 * Overdue tasks were visible on a board and nowhere else, so they reached
 * whoever already knew and no further. This walks them nightly and climbs one
 * rung at a time.
 *
 * Daily rather than every fifteen minutes, unlike the ticket ladder: a task
 * deadline is a date, not a clock, and nothing about it changes between
 * breakfast and lunch.
 */
class EscalateLateTasks extends Command
{
    protected $signature = 'tasks:escalate
        {--org= : One organisation, by id — everything by default}
        {--dry-run : Report what would happen and change nothing}
        {--seed : Record the backlog without telling anybody — for the first run}';

    protected $description = 'Escalate tasks that are overdue, paused past their promised date, or repeatedly moved';

    public function handle(TaskEscalationLadder $ladder): int
    {
        $dry = (bool) $this->option('dry-run');
        $seed = (bool) $this->option('seed');
        $only = $this->option('org') ? (int) $this->option('org') : null;

        $organizations = $only
            ? Organization::whereKey($only)->pluck('id')
            : Organization::pluck('id');

        $total = 0;

        foreach ($organizations as $organizationId) {
            // Tasks are read through the organisation scope, so the sweep has
            // to be told which one it is in rather than inferring it.
            app()->instance('current.organization_id', $organizationId);

            $result = $ladder->sweep($organizationId, $dry, $seed);
            $total += $result['escalated'];

            if ($result['escalated'] > 0) {
                $this->line(sprintf(
                    '  org %d: %d escalation(s) across %d open task(s)%s',
                    $organizationId, $result['escalated'], $result['considered'], $dry ? ' (dry run)' : '',
                ));
            }
        }

        $this->info(match (true) {
            $dry    => "{$total} escalation(s) would fire.",
            $seed   => "{$total} rung(s) recorded silently; the ladder now speaks only about movement.",
            default => "{$total} escalation(s) raised.",
        });

        return self::SUCCESS;
    }
}
