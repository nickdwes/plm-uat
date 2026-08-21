<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/**
 * A copy of the database, taken before anybody needs one.
 *
 * There was no backup at all until the day the whole dataset was dropped by a
 * stray environment variable, and it was only recoverable because every
 * importer happened to be re-runnable and the legacy dump happened to still be
 * on disk. Neither of those is a backup, and neither would have helped with a
 * record somebody typed in by hand.
 *
 * ── It verifies what it wrote ──
 *
 * A dump nobody has restored is a file, not a backup, and the moment to
 * discover it is empty or truncated is not the moment you need it. So the
 * table count is read back out of the archive and compared with the live one
 * before this reports success.
 */
class BackUpTheDatabase extends Command
{
    protected $signature = 'db:back-up
        {--path=backups : Directory to write to, relative to the project root}
        {--keep=14 : How many to keep before the oldest are removed}';

    protected $description = 'Take a verified backup of the database';

    public function handle(): int
    {
        $connection = config('database.default');
        $config     = config("database.connections.{$connection}");

        if (($config['driver'] ?? null) !== 'pgsql') {
            $this->error("Only Postgres is supported here; this connection is {$config['driver']}.");

            return self::FAILURE;
        }

        $directory = base_path($this->option('path'));

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            $this->error("Could not create {$directory}.");

            return self::FAILURE;
        }

        $file = $directory . '/' . $config['database'] . '-' . now()->format('Ymd-His') . '.dump';

        $this->line("Backing up <options=bold>{$config['database']}</> to " . basename($file));

        /*
         * Custom format rather than plain SQL: it compresses, and pg_restore
         * can pull a single table out of it, which is what somebody actually
         * wants at two in the morning when one table was truncated.
         */
        $result = Process::timeout(1800)->env(['PGPASSWORD' => $config['password']])->run([
            'pg_dump',
            '-h', $config['host'],
            '-p', (string) $config['port'],
            '-U', $config['username'],
            '-d', $config['database'],
            '--format=custom',
            '--no-owner',
            '--no-acl',
            '-f', $file,
        ]);

        if (! $result->successful()) {
            $this->error('pg_dump failed: ' . trim($result->errorOutput()));

            return self::FAILURE;
        }

        if (! $this->looksComplete($file, $config)) {
            return self::FAILURE;
        }

        $this->prune($directory, $config['database']);

        $this->info(sprintf('Backed up and verified — %s (%s).',
            basename($file), $this->size($file)));

        return self::SUCCESS;
    }

    /**
     * Read the archive back and check it holds what the database holds.
     *
     * The table listing comes out of the archive itself rather than from a
     * successful exit code, because pg_dump can exit zero having written a
     * file that is missing everything you care about.
     */
    private function looksComplete(string $file, array $config): bool
    {
        $listing = Process::timeout(300)->env(['PGPASSWORD' => $config['password']])
            ->run(['pg_restore', '--list', $file]);

        if (! $listing->successful()) {
            $this->error('The archive could not be read back: ' . trim($listing->errorOutput()));

            return false;
        }

        $inArchive = preg_match_all('/\bTABLE DATA\b/', $listing->output());

        $live = count(DB::select(
            "select tablename from pg_tables where schemaname = 'public'"
        ));

        /*
         * Empty tables are not written as data, so the archive is legitimately
         * smaller than the schema. Half is a floor, not a target: it catches a
         * truncated or half-written file without failing a database that is
         * mostly empty by nature.
         */
        if ($inArchive < $live / 2) {
            $this->error("The archive holds data for {$inArchive} tables against {$live} in the "
                . 'database. That is too few to trust — the file has been kept for inspection.');

            return false;
        }

        $this->line("  verified: data for {$inArchive} tables, {$live} tables live");

        return true;
    }

    /** Keep the recent ones and let the rest go. */
    private function prune(string $directory, string $database): void
    {
        $keep = max(1, (int) $this->option('keep'));

        $files = glob($directory . '/' . $database . '-*.dump') ?: [];

        // Newest first, so everything past the limit is the oldest.
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        foreach (array_slice($files, $keep) as $old) {
            unlink($old);
            $this->line('  removed ' . basename($old));
        }
    }

    private function size(string $file): string
    {
        $bytes = filesize($file) ?: 0;

        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024) {
                return round($bytes, 1) . $unit;
            }

            $bytes /= 1024;
        }

        return round($bytes, 1) . 'TB';
    }
}
