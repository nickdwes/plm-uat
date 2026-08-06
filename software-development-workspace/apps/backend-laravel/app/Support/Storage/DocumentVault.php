<?php

namespace App\Support\Storage;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documents encrypted before they touch storage.
 *
 * Blob storage already encrypts at rest with a key Microsoft holds, which
 * protects against a stolen disk and nothing else. It does not protect against
 * a leaked storage key, a misconfigured container, a support engineer with
 * subscription access, or a backup restored somewhere it should not be. In
 * every one of those the file is readable, and these are tender responses,
 * signed contracts, bid bonds and identity documents.
 *
 * So the bytes are encrypted by this application, with a key this application
 * holds, before they are written. Storage sees ciphertext. The only way to
 * read a document is through a route that authorises the caller and then
 * decrypts — which is the property that was asked for.
 *
 * ── The key ──────────────────────────────────────────────────────────────
 *
 * DOCUMENT_ENCRYPTION_KEY, separate from APP_KEY on purpose. APP_KEY is
 * rotated to invalidate sessions and signed URLs, and doing that must not make
 * six years of contracts unreadable. Losing this key loses the documents;
 * it belongs in Key Vault alongside the database credentials, and the runbook
 * in docs/security-runbook.md covers that.
 *
 * ── Reading what is already stored ───────────────────────────────────────
 *
 * Files written before this existed are plaintext. Ciphertext carries a magic
 * header, so a read without one is served as-is rather than failing. That is
 * deliberate and temporary: it lets encryption land without a migration
 * window, and `documents:encrypt-existing` converts the rest. Without the
 * fallback, deploying this would break every existing download at once.
 */
final class DocumentVault
{
    /** Marks a payload this class wrote. Ten bytes, then the ciphertext. */
    private const MAGIC = "KVAULT001:";

    private static ?Encrypter $cipher = null;

    /**
     * Store an upload, encrypted.
     *
     * The path is randomised rather than derived from the filename: a
     * predictable path is half of an access-control failure even behind an
     * authorising route, and the original name is kept on the record anyway.
     */
    public static function store(UploadedFile $file, string $disk, string $directory): string
    {
        $path = rtrim($directory, '/') . '/' . Str::uuid()->toString();

        Storage::disk($disk)->put($path, self::seal(
            (string) file_get_contents($file->getRealPath())
        ));

        return $path;
    }

    /** Store raw bytes — used by the backfill command. */
    public static function putContents(string $disk, string $path, string $contents): void
    {
        Storage::disk($disk)->put($path, self::seal($contents));
    }

    /**
     * The plaintext of a stored document.
     *
     * @throws FileNotFoundException when the path does not exist
     */
    public static function contents(string $disk, string $path): string
    {
        $raw = Storage::disk($disk)->get($path);

        if ($raw === null) {
            throw new FileNotFoundException($path);
        }

        return self::unseal($raw);
    }

    /**
     * A download response.
     *
     * Buffered rather than streamed from storage, because the bytes must be
     * decrypted whole — the payload is authenticated, and authenticating half
     * a file proves nothing. The 20 MB upload cap is what keeps that honest.
     */
    public static function download(string $disk, string $path, string $filename, ?string $mime = null): StreamedResponse
    {
        $plain = self::contents($disk, $path);

        return response()->streamDownload(
            static fn () => print($plain),
            $filename,
            array_filter([
                'Content-Type'   => $mime ?: 'application/octet-stream',
                'Content-Length' => (string) strlen($plain),
                // Never cached by a proxy: the authorisation happened at the
                // route, and a shared cache does not know that.
                'Cache-Control'  => 'private, no-store, max-age=0',
            ]),
        );
    }

    public static function delete(string $disk, string $path): void
    {
        Storage::disk($disk)->delete($path);
    }

    /** Whether a stored payload has been through this class. */
    public static function isSealed(string $raw): bool
    {
        return str_starts_with($raw, self::MAGIC);
    }

    // ── Internals ─────────────────────────────────────────────────────────

    private static function seal(string $plain): string
    {
        return self::MAGIC . self::cipher()->encrypt($plain, false);
    }

    private static function unseal(string $raw): string
    {
        // Written before encryption existed. Served as-is so deploying this
        // does not break every download at once; the backfill command
        // converts them.
        if (! self::isSealed($raw)) {
            return $raw;
        }

        return (string) self::cipher()->decrypt(substr($raw, strlen(self::MAGIC)), false);
    }

    private static function cipher(): Encrypter
    {
        if (self::$cipher !== null) {
            return self::$cipher;
        }

        $key = (string) config('documents.encryption_key');

        if ($key === '') {
            throw new \RuntimeException(
                'DOCUMENT_ENCRYPTION_KEY is not set. Documents cannot be stored or read without it.'
            );
        }

        $bytes = str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7)) : $key;

        /*
         * Checked here rather than left to the encrypter, which reports a
         * generic "unsupported cipher or incorrect key length" — a message that
         * sends whoever set the key looking at the cipher instead of the key.
         */
        if (strlen($bytes) !== 32) {
            throw new \RuntimeException(sprintf(
                'DOCUMENT_ENCRYPTION_KEY must decode to 32 bytes; got %d. Generate one with `php artisan documents:key`.',
                strlen($bytes),
            ));
        }

        // AES-256-GCM: authenticated, so a document altered in storage fails
        // to decrypt rather than returning quietly corrupted bytes.
        return self::$cipher = new Encrypter($bytes, 'aes-256-gcm');
    }
}
