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

