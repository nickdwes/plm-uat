<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Copy documents from the local private disks to Azure Blob Storage.
 *
 * Copies, never moves. The local files stay exactly where they are, so a
 * failed or half-finished run leaves the application working on local storage
 * and the worst outcome is wasted bandwidth. Deleting the originals is a
 * separate, deliberate act once the cutover has been proven in production —
 * `--prune` exists for that and refuses to run without a verified copy.
 *
 * Every file is verified by MD5 after upload. Object storage acknowledging a
 * write is not the same as the bytes being intact, and a silently truncated
 * CV is indistinguishable from a good one until someone opens it.
 */
class MigrateDocumentsToAzure extends Command
{
    protected $signature = 'documents:migrate-to-azure
                            {--disk=* : Limit to specific local disks}
                            {--dry-run : List what would be copied and stop}
                            {--prune : Delete local originals whose copies are verified}';

    protected $description = 'Copy private documents to Azure Blob Storage, verifying each by checksum';

    /**
     * Local disk => Azure disk.
     *
     * Paths are preserved exactly, so the values already stored in
     * `resume_path`, `storage_path` and friends keep resolving after the
     * cutover. Rewriting paths and moving bytes in the same operation would
     * mean a failure could leave the database pointing at neither copy.
     */
    private const DISKS = [
        'candidate_docs_local'     => 'azure_candidate_docs',
        'preboarding_docs_local'   => 'azure_preboarding_docs',
        'employee_documents_local' => 'azure_employee_documents',
        'training_evidence_local'  => 'azure_training_evidence',
    ];

    public function handle(): int
    {
        $only    = $this->option('disk');
        $dryRun  = (bool) $this->option('dry-run');
        $prune   = (bool) $this->option('prune');

        if ($prune && $dryRun) {
            $this->error('--prune and --dry-run are contradictory.');
            return self::FAILURE;
        }

        $copied = $failed = $skipped = $pruned = 0;

        foreach (self::DISKS as $localName => $azureName) {
            if ($only && ! in_array($localName, $only, true)) {
                continue;
            }

            $local = Storage::disk($localName);
            $azure = Storage::disk($azureName);
            $files = $local->allFiles();

            $this->newLine();
            $this->line("<info>{$localName}</info> → {$azureName} (" . count($files) . ' file(s))');

            foreach ($files as $path) {
                // Laravel writes a .gitignore into each disk root; it is
                // scaffolding, not a document.
                if (basename($path) === '.gitignore') {
                    continue;
                }

                $expected = md5($local->get($path));

                if ($azure->exists($path) && md5($azure->get($path)) === $expected) {
                    $this->line("  <comment>skip</comment>  {$path} (already present and identical)");
                    $skipped++;
                    $this->pruneIfAsked($prune, $local, $path, $pruned);
                    continue;
                }

                if ($dryRun) {
                    $this->line("  copy  {$path}");
                    $copied++;
                    continue;
                }

                try {
                    // Streamed rather than read whole: a 10 MB cap per upload
                    // is enforced at the HTTP layer, but nothing stops an
                    // operator putting something larger on the disk directly.
                    $stream = $local->readStream($path);
                    $azure->writeStream($path, $stream);
                    if (is_resource($stream)) {
                        fclose($stream);
                    }

                    $actual = md5($azure->get($path));
                    if ($actual !== $expected) {
                        // Leave the bad copy in place rather than deleting it:
                        // it is evidence, and the local original is untouched.
                        $this->error("  FAIL  {$path} — checksum mismatch after upload");
                        $failed++;
                        continue;
                    }

                    $this->line("  <info>ok</info>    {$path}");
                    $copied++;
                    $this->pruneIfAsked($prune, $local, $path, $pruned);
                } catch (\Throwable $e) {
                    $this->error("  FAIL  {$path} — " . $e->getMessage());
                    $failed++;
                }
            }
        }

        $this->newLine();
        $this->line(sprintf(
            'copied %d, skipped %d, failed %d%s',
            $copied, $skipped, $failed, $prune ? ", pruned {$pruned}" : ''
        ));

        if ($dryRun) {
            $this->comment('Dry run — nothing was written.');
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Delete a local original, but only ever after its copy has been verified
     * in this same run.
     */
    private function pruneIfAsked(bool $prune, $local, string $path, int &$pruned): void
    {
        if (! $prune) {
            return;
        }

        $local->delete($path);
        $pruned++;
        $this->line("  <comment>pruned</comment> local {$path}");
    }
}
