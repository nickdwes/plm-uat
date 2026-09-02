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
 * The same question asked of suppliers: whose tax certificate, insurance or
 * OEM authorisation is about to lapse. Fifteen minutes after the org's own
 * compliance sweep so the two never contend for the mail path.
 */
Schedule::command('vendors:remind-expiries')->dailyAt('07:15');

/*
 * Raise whatever recurring task schedules owe.
 *
 * Early, so a task due today is in somebody's list before they open it.
 * `withoutOverlapping` is belt to the generator's braces — every task it makes
 * is keyed to its schedule and occurrence date, so a double run makes nothing,
 * but there is no reason to have two runs fighting over the same rows.
 */
Schedule::command('tasks:generate-recurring')->dailyAt('06:00')->withoutOverlapping();

// Remind task owners to plan and authorize travel five days before a travel
// task starts.
Schedule::command('tasks:remind-travel')->dailyAt('06:30')->withoutOverlapping();

/*
 * Climb the ladder on work that has gone past its marks — overdue, paused
 * longer than promised, or moved once too often.
 *
 * Daily rather than the ticket ladder's fifteen minutes: a task deadline is a
 * date, and nothing about it changes between breakfast and lunch. Early, so the
 * escalation is waiting when somebody opens their morning.
 */
Schedule::command('tasks:escalate')->dailyAt('06:45')->withoutOverlapping();

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

/*
 * Scheduled support rules — chasing parked tickets, closing ones nobody came
 * back on.
 *
 * Hourly rather than every few minutes. These rules reach customers, and the
 * granularity of "three days without a reply" does not reward checking more
 * often than the cooldowns allow anyway.
 */
Schedule::command('support:automations')->hourly()->withoutOverlapping();

/*
 * A verified copy of the database, nightly.
 *
 * There was none at all until the day a stray environment variable pointed the
 * test suite at production and it dropped every table. What saved it was luck
 * dressed as design — the importers happened to be re-runnable and the legacy
 * dump happened to still be on disk — and neither would have brought back a
 * record somebody typed in by hand.
 *
 * Half past two because it is after the day's work and before anybody starts,
 * and the dump takes seconds at this size. Fourteen kept: long enough that a
 * problem noticed a week late still has a copy from before it, short enough
 * that nobody has to think about disk.
 */
Schedule::command('db:back-up --keep=14')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->onFailure(fn () => logger()->error('The nightly database backup failed.'));
