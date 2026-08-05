<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Tells someone when the database is not being backed up.
 *
 * Until this existed, a failing backup and a working one looked identical: the
 * script wrote to a log nobody reads and cron mailed a local mailbox nobody
 * opens. The failure was silent by construction, which is the worst property a
 * backup system can have.
 *
 * Three modes, because backups fail in three different ways:
 *
 *   --failed=REASON   the backup script itself broke, and knows it
 *   (no options)      freshness check: is last night's dump actually there?
 *   --heartbeat       always report, healthy or not
 *
 * The freshness check is the important one. A script that only alerts when it
 * fails cannot alert when it never runs — cron disabled, script moved, Docker
 * down — and "never ran" is indistinguishable from "nothing to report".
 *
 * --heartbeat exists for the same reason one level up: alerting that never
 * fires is indistinguishable from alerting that is broken. A weekly message
 * proves the whole chain still works.
 */
class CheckDatabaseBackup extends Command
{
    protected $signature = 'backup:check
                            {--failed= : Report a backup run that failed, with this reason}
                            {--max-age=26 : Hours before the newest backup counts as stale}
                            {--heartbeat : Send a report even when everything is healthy}';

    protected $description = 'Verify a recent database backup exists, and raise an alert when it does not';

    public function handle(): int
    {
        $recipient = config('backup.alert_email') ?: config('mail.from.address');

        if (! $recipient) {
            // Nowhere to send an alert is itself the problem being solved.
            Log::error('backup:check has no recipient configured');
            $this->error('No BACKUP_ALERT_EMAIL or MAIL_FROM_ADDRESS configured.');
            return self::FAILURE;
        }

        if ($reason = $this->option('failed')) {
            $this->alert_("Database backup FAILED", $recipient, $reason);
            return self::FAILURE;
        }

        [$newest, $age, $count] = $this->inspect();

        if ($newest === null) {
            $this->alert_(
                'Database backup MISSING',
                $recipient,
                "No backup files were found in the hrms-db-backups container at all.\n\n"
                . "Either no backup has ever succeeded, or something has removed them."
            );
            return self::FAILURE;
        }

        $maxAge = (int) $this->option('max-age');

        if ($age > $maxAge) {
            $this->alert_(
                'Database backup STALE',
                $recipient,
                sprintf(
                    "The newest database backup is %d hours old, which is beyond the %d hour limit.\n\n"
                    . "Newest file: %s\n\n"
                    . "The backup runs nightly at 02:15 UTC. If nothing has changed on the server, "
                    . "check that cron is still scheduled and that the Docker containers are running.",
                    $age, $maxAge, $newest
                )
            );
            return self::FAILURE;
        }

        $summary = sprintf(
            "Newest backup: %s\nAge: %d hour(s)\nFiles in container: %d",
            $newest, $age, $count
        );

        $this->info('Backup is current.');
        $this->line($summary);

        if ($this->option('heartbeat')) {
            Mail::raw(
                "The HRMS database backup is healthy.\n\n{$summary}\n\n"
                . "This message is sent weekly. If it stops arriving, the alerting itself "
                . "has broken and the backups are no longer being watched.",
                fn ($m) => $m->to($recipient)->subject('HRMS database backup: healthy')
            );
            $this->info("Heartbeat sent to {$recipient}.");
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0: ?string, 1: int, 2: int} newest file, age in hours, file count
     */
    private function inspect(): array
    {
        $disk = Storage::disk('azure_db_backups');

        // Only the dumps matter. Checksums and globals are written alongside
        // each one, so counting them would mask a run that uploaded metadata
        // and then failed before the dump itself.
        $dumps = array_values(array_filter(
            $disk->allFiles('hrms'),
            fn (string $p) => str_ends_with($p, '.dump')
        ));

        if ($dumps === []) {
            return [null, 0, 0];
        }

        $newest = null;
        $latest = 0;

        foreach ($dumps as $path) {
            $modified = $disk->lastModified($path);
            if ($modified > $latest) {
                $latest = $modified;
                $newest = $path;
            }
        }

        return [$newest, (int) floor((time() - $latest) / 3600), count($dumps)];
    }

    private function alert_(string $subject, string $recipient, string $body): void
    {
        $message = "{$body}\n\n"
            . "Host: " . gethostname() . "\n"
            . "Checked: " . now()->toDateTimeString() . " UTC\n\n"
            . "Backups are written to the hrms-db-backups container in the knems storage account.\n"
            . "The script is at infra/azure/backup-database.sh and its log at ~/backups/backup.log.";

        Log::error("backup alert: {$subject}", ['body' => $body]);

        try {
            Mail::raw($message, fn ($m) => $m->to($recipient)->subject("HRMS: {$subject}"));
            $this->error("{$subject} — alert sent to {$recipient}");
        } catch (\Throwable $e) {
            // If mail is down too, the log is the last line of defence.
            Log::critical('backup alert could not be emailed', ['error' => $e->getMessage()]);
            $this->error("{$subject} — AND the alert email failed: {$e->getMessage()}");
        }
    }
}
