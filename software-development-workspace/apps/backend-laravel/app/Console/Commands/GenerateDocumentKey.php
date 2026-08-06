<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GenerateDocumentKey extends Command
{
    protected $signature = 'documents:key';

    protected $description = 'Generate a DOCUMENT_ENCRYPTION_KEY for document encryption at rest';

    public function handle(): int
    {
        $key = 'base64:' . base64_encode(random_bytes(32));

        $this->line('');
        $this->line('  DOCUMENT_ENCRYPTION_KEY=' . $key);
        $this->line('');
        $this->warn('  Store this in Key Vault before deploying. Losing it loses every');
        $this->warn('  document written after it was set — there is no recovery path.');
        $this->line('');

        return self::SUCCESS;
    }
}
