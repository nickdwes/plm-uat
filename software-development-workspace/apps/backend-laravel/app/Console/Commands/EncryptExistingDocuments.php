<?php

namespace App\Console\Commands;

use App\Support\Storage\DocumentVault;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Convert documents written before encryption existed.
 *
 * Idempotent: a file already carrying the vault header is skipped, so this can
 * be run repeatedly and interrupted safely. It reads and rewrites in place —
 * which is why it runs after a backup, and why the runbook says so.
 */
class EncryptExistingDocuments extends Command
{
    protected $signature = 'documents:encrypt-existing {--disk=* : Limit to named disks} {--dry-run}';

    protected $description = 'Encrypt documents stored before encryption at rest was introduced';

    public function handle(): int
    {
        $disks = $this->option('disk') ?: config('documents.encrypted_disks');
        $dry   = (bool) $this->option('dry-run');

        $converted = $skipped = $failed = 0;

        foreach ($disks as $disk) {
            $this->line("Disk: {$disk}");

            foreach (Storage::disk($disk)->allFiles() as $path) {
                try {
                    $raw = Storage::disk($disk)->get($path);

                    if ($raw === null || DocumentVault::isSealed($raw)) {
                        $skipped++;
                        continue;
                    }

                    if (! $dry) {
                        DocumentVault::putContents($disk, $path, $raw);
                    }

                    $converted++;
                } catch (\Throwable $e) {
                    // Reported rather than thrown: one unreadable file must not
                    // stop the rest being protected.
                    $this->error("  {$path}: {$e->getMessage()}");
                    $failed++;
                }
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '%s%d converted, %d already encrypted, %d failed.',
            $dry ? '[dry run] ' : '', $converted, $skipped, $failed,
        ));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
