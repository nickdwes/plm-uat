<?php

namespace App\Console\Commands;

use App\Models\Task\TaskHandover;
use Illuminate\Console\Command;

/**
 * Hand covered tasks back when the leave is over.
 *
 * Without this, cover never ends. The coverer keeps seeing work that is no
 * longer theirs, the owner comes back to a list that does not show what they
 * own, and after two consecutive leaves nobody can say who has what — which is
 * the drift the handover record exists to prevent in the first place.
 *
 * Idempotent, and runs across every organisation: this is a cron job with no
 * request behind it, so there is no current tenant and quietly releasing for
 * only one would leave the rest holding work forever.
 */
class ReleaseFinishedHandovers extends Command
{
    protected $signature = 'tasks:release-handovers';

    protected $description = 'End task cover whose leave has finished';

    public function handle(): int
    {
        $released = TaskHandover::withoutGlobalScopes()
            ->standing()
            // Yesterday or earlier: somebody back today held the task through
            // yesterday, and ending it a day early is how a Monday morning
            // starts with an unowned support case.
            ->whereDate('ends_on', '<', now()->toDateString())
            ->update(['released_at' => now()]);

        $this->info("Handed back {$released} task(s).");

        return self::SUCCESS;
    }
}
