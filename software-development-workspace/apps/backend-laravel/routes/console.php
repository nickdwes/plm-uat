<?php

use App\Console\Commands\SendComplianceReminders;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ── Scheduled tasks ───────────────────────────────────────────────────────────

// Run compliance reminders every day at 07:00 (server time / Africa/Nairobi).
Schedule::command('compliance:reminders')->dailyAt('07:00');

/*
 * Raise whatever recurring task schedules owe.
 *
 * Early, so a task due today is in somebody's list before they open it.
 * `withoutOverlapping` is belt to the generator's braces — every task it makes
 * is keyed to its schedule and occurrence date, so a double run makes nothing,
 * but there is no reason to have two runs fighting over the same rows.
 */
Schedule::command('tasks:generate-recurring')->dailyAt('06:00')->withoutOverlapping();

/*
 * Hand covered tasks back once the leave is over.
 *
 * Early, so somebody returning finds their own work in their list on the
 * morning they walk in rather than the morning after.
 */
Schedule::command('tasks:release-handovers')->dailyAt('05:30')->withoutOverlapping();

/*
 * Ask people to close their day, and tell managers about the days nobody closed.
 *
 * Hourly rather than at a fixed time, because the end of the working day is a
 * policy and two organisations do not finish together — the command decides
 * whether it is home time for each of them. Both stages stamp the day they
 * acted on, so running it more often changes nothing except how promptly people
 * are asked.
 */
Schedule::command('attendance:remind-open-commitments')->hourly()->withoutOverlapping();

/*
 * Climb the escalation ladder on tickets that have run past their thresholds.
 *
 * Every fifteen minutes: an escalation that arrives an hour after the threshold
 * is an escalation nobody can act on, and the command is cheap — it only looks
 * at live, unpaused tickets that already carry a target. Each rung fires once,
 * guarded by its timestamp, so re-running is safe.
 */
Schedule::command('support:escalate')->everyFifteenMinutes()->withoutOverlapping();
