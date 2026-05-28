<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class SharePointService
{
    private string $tenantId;
    private string $clientId;
    private string $clientSecret;
    private string $siteUrl;
    private string $library;

    public function __construct()
    {
        $this->tenantId     = (string) config('sharepoint.tenant_id');
        $this->clientId     = (string) config('sharepoint.client_id');
        $this->clientSecret = (string) config('sharepoint.client_secret');
        $this->siteUrl      = rtrim((string) config('sharepoint.site_url'), '/');
        $this->library      = (string) config('sharepoint.library');
    }

    // ── Auth ─────────────────────────────────────────────────────────────────

    private function accessToken(): string
    {
        // Cache for 45 minutes (tokens are valid for 60 min)
        return Cache::remember('sharepoint_access_token', 2700, function () {
            $response = Http::asForm()->post(
                "https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token",
                [
                    'grant_type'    => 'client_credentials',
                    'client_id'     => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'scope'         => 'https://graph.microsoft.com/.default',
                ]
            );

            if (! $response->successful()) {
                throw new RuntimeException('SharePoint authentication failed: ' . $response->body());
            }

            return (string) $response->json('access_token');
        });
    }

    // ── Site / Drive resolution ───────────────────────────────────────────────

    private function siteId(): string
    {
        return Cache::remember('sharepoint_site_id', 86400, function () {
            $parsed = parse_url($this->siteUrl);
            $host   = $parsed['host'] ?? '';
            $path   = ltrim($parsed['path'] ?? '', '/');

            $response = Http::withToken($this->accessToken())
                ->get("https://graph.microsoft.com/v1.0/sites/{$host}:/{$path}");

            if (! $response->successful()) {
                throw new RuntimeException('Cannot resolve SharePoint site: ' . $response->body());
            }

            return (string) $response->json('id');
        });
    }

    private function driveId(): string
    {
        return Cache::remember('sharepoint_drive_id', 86400, function () {
            $siteId   = $this->siteId();
            $response = Http::withToken($this->accessToken())
                ->get("https://graph.microsoft.com/v1.0/sites/{$siteId}/drives");

            if (! $response->successful()) {
                throw new RuntimeException('Cannot list SharePoint drives: ' . $response->body());
            }

            $drives  = collect($response->json('value') ?? []);
            $library = $this->library;

            // Match by name, fall back to first drive
            $drive = $drives->first(fn($d) => $d['name'] === $library)
                ?? $drives->first();

            if (! $drive) {
                throw new RuntimeException('No SharePoint document library found.');
            }

            return (string) $drive['id'];
        });
    }

    // ── File operations ───────────────────────────────────────────────────────

    /**
     * Upload a file to SharePoint.
     * Returns ['item_id' => string, 'web_url' => string]
     *
     * Files ≤ 4 MB use simple PUT upload.
     * Files > 4 MB use a resumable upload session.
     */
    public function upload(string $tempPath, string $filename, string $mimeType): array
    {
        $driveId  = $this->driveId();
        $token    = $this->accessToken();
        $contents = file_get_contents($tempPath);

        if ($contents === false) {
            throw new RuntimeException('Could not read uploaded file from temp path.');
        }

        $size = strlen($contents);

        if ($size <= 4 * 1024 * 1024) {
            // Simple upload (≤ 4 MB)
            $response = Http::withToken($token)
                ->withBody($contents, $mimeType)
                ->put("https://graph.microsoft.com/v1.0/drives/{$driveId}/root:/{$filename}:/content");

        } else {
            // Resumable upload session for large files
            $sessionResp = Http::withToken($token)
                ->post(
                    "https://graph.microsoft.com/v1.0/drives/{$driveId}/root:/{$filename}:/createUploadSession",
                    ['item' => ['@microsoft.graph.conflictBehavior' => 'replace']]
                );

            if (! $sessionResp->successful()) {
                throw new RuntimeException('Cannot create SharePoint upload session: ' . $sessionResp->body());
            }

            $uploadUrl = (string) $sessionResp->json('uploadUrl');
            $chunkSize = 3 * 1024 * 1024; // 3 MB chunks (must be multiple of 320 KiB)
            $offset    = 0;
            $response  = null;

            while ($offset < $size) {
                $chunk    = substr($contents, $offset, $chunkSize);
                $chunkLen = strlen($chunk);
                $end      = $offset + $chunkLen - 1;

                // Upload session URLs are pre-authenticated — no Bearer token needed
                $response = Http::withHeaders([
                    'Content-Length' => (string) $chunkLen,
                    'Content-Range'  => "bytes {$offset}-{$end}/{$size}",
                    'Content-Type'   => $mimeType,
                ])->withBody($chunk, $mimeType)->put($uploadUrl);

                $offset += $chunkLen;
            }
        }

        if (! $response || ! $response->successful()) {
            throw new RuntimeException('SharePoint upload failed: ' . ($response?->body() ?? 'unknown error'));
        }

        return [
            'item_id' => (string) $response->json('id'),
            'web_url' => (string) $response->json('webUrl'),
        ];
    }

    /**
     * Delete a file from SharePoint by Graph item ID.
     * Silently ignores 404 (already deleted).
     */
    public function delete(string $itemId): void
    {
        $driveId = $this->driveId();
        Http::withToken($this->accessToken())
            ->delete("https://graph.microsoft.com/v1.0/drives/{$driveId}/items/{$itemId}");
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Build a SharePoint-safe filename from the document label, date, and file extension.
     * Example: "KRA Tax Compliance Certificate (TCC)" + "pdf"
     *       => "kra_tax_compliance_certificate_tcc_2026-04-21.pdf"
     */
    public static function buildFilename(string $documentLabel, string $extension): string
    {
        $slug = Str::slug($documentLabel, '_');
        $date = now()->format('Y-m-d');
        $ext  = ltrim(strtolower($extension), '.');

        return "{$slug}_{$date}.{$ext}";
    }
}
