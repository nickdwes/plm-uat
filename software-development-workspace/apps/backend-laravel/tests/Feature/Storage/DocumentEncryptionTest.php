<?php

namespace Tests\Feature\Storage;

use App\Support\Storage\DocumentVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Every document class is encrypted, and everything that reads one can decrypt.
 *
 * Three disks were missing from the encrypted list — employee documents,
 * preboarding attachments and training evidence — which meant employment
 * contracts, passport scans and KRA PIN certificates were the one class stored
 * as plain files, while tender responses were sealed.
 *
 * And on the disk that *was* on the list, the CV download read raw bytes. It
 * worked only because every CV on file happened to predate the portal. Sealing
 * them — which the runbook tells you to do — would have turned every CV
 * download in the system into a corrupt file on the same day.
 *
 * These tests are about that pairing. Encrypting on the way in is half a
 * feature; the half that matters is that somebody can still open the file.
 */
class DocumentEncryptionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The classes that hold documents this application encrypts.
     *
     * Named here as well as in config so that dropping one from the config is
     * a test failure rather than a silent downgrade — which is exactly how the
     * three sensitive ones came to be missing.
     */
    private const MUST_BE_ENCRYPTED = [
        'employee_documents',
        'preboarding_docs',
        'training_evidence',
        'candidate_docs',
        'opportunity_documents',
        'lead_documents',
        'account_documents',
        'travel_evidence',
        'project_documents',
    ];

    public function test_every_document_class_is_on_the_encrypted_list(): void
    {
        $configured = config('documents.encrypted_disks');

        $missing = array_values(array_diff(self::MUST_BE_ENCRYPTED, $configured));

        $this->assertSame(
            [],
            $missing,
            'These document classes are stored unencrypted: ' . implode(', ', $missing),
        );
    }

    /** The bytes on disk are not the bytes that were uploaded. */
    public function test_a_stored_document_is_unreadable_on_disk(): void
    {
        Storage::fake('employee_documents');

        $path = DocumentVault::store(
            UploadedFile::fake()->createWithContent('contract.pdf', 'BASIC SALARY KES 250,000'),
            'employee_documents',
            '1/1/contract',
        );

        $onDisk = Storage::disk('employee_documents')->get($path);

        $this->assertStringNotContainsString('250,000', $onDisk);
        $this->assertTrue(DocumentVault::isSealed($onDisk));
    }

    /** And they come back exactly as they went in. */
    public function test_a_stored_document_round_trips(): void
    {
        Storage::fake('employee_documents');

        $plain = 'BASIC SALARY KES 250,000';

        $path = DocumentVault::store(
            UploadedFile::fake()->createWithContent('contract.pdf', $plain),
            'employee_documents',
            '1/1/contract',
        );

        $this->assertSame($plain, DocumentVault::contents('employee_documents', $path));
    }

    /**
     * The trap that was waiting.
     *
     * Files written before encryption are served as-is, so deploying this does
     * not break the six CVs already on file — and the backfill can convert them
     * afterwards without anything noticing.
     */
    public function test_a_file_written_before_encryption_still_reads(): void
    {
        Storage::fake('candidate_docs');

        Storage::disk('candidate_docs')->put('resumes/7/old.pdf', 'a plain CV');

        $this->assertSame(
            'a plain CV',
            DocumentVault::contents('candidate_docs', 'resumes/7/old.pdf'),
        );
    }

    /** Converting it afterwards leaves it readable. */
    public function test_a_backfilled_file_still_reads(): void
    {
        Storage::fake('candidate_docs');

        $disk = Storage::disk('candidate_docs');
        $disk->put('resumes/7/old.pdf', 'a plain CV');

        // What documents:encrypt-existing does.
        DocumentVault::putContents('candidate_docs', 'resumes/7/old.pdf', $disk->get('resumes/7/old.pdf'));

        $this->assertTrue(DocumentVault::isSealed($disk->get('resumes/7/old.pdf')));
        $this->assertSame(
            'a plain CV',
            DocumentVault::contents('candidate_docs', 'resumes/7/old.pdf'),
        );
    }

    /**
     * Nothing reads a document class straight off the disk any more.
     *
     * Asserted structurally because the failure is silent: a raw read of a
     * sealed file returns ciphertext with a 200 and no error anywhere. The next
     * one added should fail here rather than in somebody's downloads folder.
     */
    public function test_no_download_path_reads_a_document_disk_raw(): void
    {
        $offenders = [];

        $files = array_merge(
            glob(app_path('Http/Controllers') . '/*.php'),
            glob(app_path('Http/Controllers') . '/*/*.php'),
            glob(app_path('Models') . '/*.php'),
            glob(app_path('Models') . '/*/*.php'),
        );

        foreach ($files as $file) {
            $body = file_get_contents($file);

            // A readStream() on one of the encrypted disks, without the vault
            // anywhere in the same file, is a raw read of an encrypted file.
            if (! preg_match('/readStream\(/', $body)) {
                continue;
            }

            $touchesEncryptedDisk = false;
            foreach (self::MUST_BE_ENCRYPTED as $disk) {
                if (str_contains($body, "'{$disk}'")) {
                    $touchesEncryptedDisk = true;
                    break;
                }
            }

            if ($touchesEncryptedDisk && ! str_contains($body, 'DocumentVault')) {
                $offenders[] = basename($file, '.php');
            }
        }

        sort($offenders);

        $this->assertSame(
            [],
            $offenders,
            'These read an encrypted document class straight off the disk, so they serve ciphertext: '
                . implode(', ', $offenders),
        );
    }
}
