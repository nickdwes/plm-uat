<?php

namespace App\Console\Commands;

use App\Services\Attendance\DayCloseReminder;
use Illuminate\Console\Command;

/**
 * Ask people to close their day; tell managers about the days nobody closed.
 *
 * Runs hourly rather than at one fixed time, because the end of the working day
 * is a policy and two organisations do not finish together. The service decides
 * whether it is home time for each of them; this only decides how often to ask.
 *
 * Safe to run as often as you like. Both stages stamp the day they acted on, so
 * a second run in the same hour finds nothing left to do.
 */
class RemindOpenCommitments extends Command
{
    protected $signature = 'attendance:remind-open-commitments';

    protected $description = 'Nudge people with unanswered commitments, and escalate the days nobody closed';

    public function handle(DayCloseReminder $reminder): int
    {
        $nudged = 0;
        $raised = 0;

        foreach ($reminder->organizations() as $organizationId) {
            $nudged += $reminder->remind((int) $organizationId);
            $raised += $reminder->escalate((int) $organizationId);
        }

        $this->info("Nudged {$nudged}, escalated {$raised}.");

        return self::SUCCESS;
    }
}
