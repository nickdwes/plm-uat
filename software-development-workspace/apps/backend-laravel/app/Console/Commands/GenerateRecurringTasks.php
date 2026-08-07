<?php

namespace App\Console\Commands;

use App\Models\Task\TaskRecurrence;
use App\Services\Task\TaskGenerator;
use Illuminate\Console\Command;

/**
 * Raise whatever the schedules owe.
 *
 * Safe to run as often as you like, and safe to run twice at once — every task
 * it makes is keyed to its recurrence and its occurrence date, so a second run
 * finds them already there and makes nothing.
 *
 * Runs across every organisation deliberately: this is a cron job with no
 * request behind it, so there is no current tenant to scope to and quietly
 * generating for only one would leave the rest silently unserved.
 */
class GenerateRecurringTasks extends Command
{
    protected $signature = 'tasks:generate-recurring {--dry-run : List what would be raised without raising it}';

    protected $description = 'Raise tasks that recurring schedules are due to produce';

    public function handle(TaskGenerator $generator): int
    {
        $schedules = TaskRecurrence::withoutGlobalScopes()->active()->get();

        if ($schedules->isEmpty()) {
            $this->info('No active schedules.');

            return self::SUCCESS;
        }

        $total = 0;

        foreach ($schedules as $schedule) {
            $due = $schedule->due(now()->toImmutable());

            if ($due === []) {
                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("{$schedule->title}: " . count($due) . ' due — '
                    . implode(', ', array_map(fn ($d) => $d->toDateString(), $due)));
                $total += count($due);

                continue;
            }

            $made = $generator->runRecurrence($schedule);
            $total += count($made);

            $this->line("{$schedule->title}: raised " . count($made));
        }

        $this->info(($this->option('dry-run') ? 'Would raise ' : 'Raised ') . $total . ' task(s).');

        return self::SUCCESS;
    }
}
