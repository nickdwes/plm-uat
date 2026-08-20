<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Support\AutomationEngine;
use Illuminate\Console\Command;

/**
 * The scheduled half of the rules.
 *
 * Chasing a parked ticket, closing one nobody has come back on, nudging work
 * that has sat unassigned. All of it deliberately on a clock rather than an
 * event, because "nothing has happened for three days" is not something a
 * ticket can tell you at the moment it happens.
 */
class RunSupportAutomations extends Command
{
    protected $signature = 'support:automations {--org= : One organisation only}';

    protected $description = 'Apply scheduled support automation rules';

    public function handle(AutomationEngine $engine): int
    {
        $organizations = $this->option('org')
            ? Organization::where('id', $this->option('org'))->get()
            : Organization::all();

        $total = 0;

        foreach ($organizations as $organization) {
            app()->instance('current.organization_id', $organization->id);

            $runs = $engine->runScheduled($organization->id);

            foreach ($runs as $run) {
                $this->line(sprintf(
                    '%s %s — %s',
                    $run->was_dry_run ? '[rehearsal]' : '           ',
                    $run->ticket?->reference ?? '?',
                    $run->summary,
                ));
            }

            $total += count($runs);
        }

        $this->info("{$total} rule firings across {$organizations->count()} organisations.");

        return self::SUCCESS;
    }
}
