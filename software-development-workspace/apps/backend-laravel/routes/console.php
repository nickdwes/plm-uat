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

