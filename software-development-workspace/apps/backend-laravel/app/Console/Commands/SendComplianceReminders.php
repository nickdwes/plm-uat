<?php

namespace App\Console\Commands;

use App\Models\OrgComplianceDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Scans all compliance documents and sends email reminders
 * when expiry is approaching at the configured thresholds.
 *
 * Default thresholds: 180, 90, 30, 23, 16, 9, 6, 5, 4, 3, 2, 1 days before expiry.
 * Run daily: php artisan compliance:reminders
 */
class SendComplianceReminders extends Command
{
    protected $signature   = 'compliance:reminders {--dry-run : List without sending}';
    protected $description = 'Send compliance document expiry reminders';

    public function handle(): int
    {
        $today     = now()->startOfDay();
        $sent      = 0;
        $skipped   = 0;
        $dryRun    = $this->option('dry-run');

        // Load only docs that have reminders active, an expiry date, and recipients
        $docs = OrgComplianceDocument::query()
            ->whereNotNull('expiry_date')
            ->where('reminder_active', true)
            ->whereNotNull('reminder_recipients')
            ->with('organization')
            ->get();

        foreach ($docs as $doc) {
            if ($doc->is_perpetual) {
                continue;
            }

            $expiry   = $doc->expiry_date->copy()->startOfDay();
            $daysLeft = (int) $today->diffInDays($expiry, false);

            // Already expired — still notify once per day in final urgency
            if ($daysLeft < 0) {
                $daysLeft = $daysLeft; // negative
            }

            $thresholds = $doc->reminder_thresholds ?? OrgComplianceDocument::defaultThresholds();

            // Check if today matches any threshold
            if (! in_array($daysLeft, $thresholds, true)) {
                $skipped++;
                continue;
            }

            $recipients = array_filter(
                array_map('trim', explode(',', $doc->reminder_recipients ?? ''))
            );

            if (empty($recipients)) {
                $skipped++;
                continue;
            }

            $urgency = $this->urgencyLabel($daysLeft);
            $subject = "[{$urgency}] Compliance document expiring: {$doc->document_label}";
            $body    = $this->buildEmailBody($doc, $daysLeft, $urgency);

            if ($dryRun) {
                $this->line("DRY-RUN | {$doc->organization->name} | {$doc->document_label} | {$daysLeft} days | → " . implode(', ', $recipients));
            } else {
                foreach ($recipients as $email) {
                    try {
                        Mail::raw($body, function ($message) use ($email, $subject) {
                            $message->to($email)->subject($subject);
                        });
                    } catch (\Throwable $e) {
                        Log::error("ComplianceReminder: failed to send to {$email}: " . $e->getMessage());
                    }
                }

                $doc->update(['last_reminder_sent_at' => now()]);
                $doc->appendAudit('reminder_sent', 'system', "Reminder sent at {$daysLeft} days before expiry.");
            }

            $sent++;
        }

        $this->info("Compliance reminders: {$sent} sent, {$skipped} skipped." . ($dryRun ? ' (dry-run)' : ''));
        return Command::SUCCESS;
    }

    private function urgencyLabel(int $daysLeft): string
    {
        if ($daysLeft <= 0)  return 'EXPIRED';
        if ($daysLeft <= 7)  return 'CRITICAL – Expiring This Week';
        if ($daysLeft <= 30) return 'URGENT – Expiring This Month';
        if ($daysLeft <= 90) return 'WARNING – Expiring in 3 Months';
        return 'NOTICE – Expiring in 6 Months';
    }

    private function buildEmailBody(OrgComplianceDocument $doc, int $daysLeft, string $urgency): string
    {
        $org    = $doc->organization->name;
        $label  = $doc->document_label;
        $expiry = $doc->expiry_date->format('d M Y');
        $owner  = $doc->owner_name ? "Assigned to: {$doc->owner_name}" : '';

        $lines = [
            "COMPLIANCE REMINDER — {$urgency}",
            str_repeat('=', 60),
            "",
            "Organisation : {$org}",
            "Document     : {$label}",
            "Expiry Date  : {$expiry}",
            $daysLeft > 0
                ? "Days Remaining: {$daysLeft} day(s)"
                : "Status       : EXPIRED ({$daysLeft} days ago)",
            $owner,
            "",
            "---- Renewal Guide ----",
            $doc->renewal_guide ?? "No renewal guide available. Please update via the HRMS Compliance dashboard.",
            "",
            str_repeat('-', 60),
            "This is an automated reminder from your HRMS Compliance module.",
            "Log in to review and update this document: " . (config('app.url') . '/#/org'),
        ];

        return implode("\n", array_filter($lines, fn($l) => $l !== null));
    }
}
