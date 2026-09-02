<?php

namespace App\Services\Account;

use App\Models\Account\Account;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Auto-populates a client's logo from its web domain.
 *
 * A logo service is keyed by domain. When the domain is not given it is derived
 * from the account's contact emails (a corporate address gives it away, a
 * free-mail one does not); for well-known institutions the domain is supplied
 * directly. The image comes from Google's favicon service and is stored on the
 * same public disk the manual upload uses, so the rest of the app treats an
 * auto-fetched logo exactly like an uploaded one.
 *
 * Google's cache is keyed inconsistently — a site's icon may sit under the bare
 * host, the www host, or the full URL — so each domain is tried in all three
 * forms before giving up. Only the domain leaves the system (sent to Google),
 * never anything about the contact.
 */
class AccountLogoFetcher
{
    /** Personal-mail domains that say nothing about the company. */
    private const FREE_MAIL = [
        'gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.co.uk', 'ymail.com',
        'hotmail.com', 'outlook.com', 'live.com', 'msn.com', 'icloud.com',
        'me.com', 'aol.com', 'protonmail.com', 'proton.me', 'gmx.com', 'mail.com',
        'zoho.com', 'yandex.com',
    ];

    /**
     * The domain to fetch a logo from — the primary contact's, else any active
     * contact's, corporate email domain. Null when none is corporate.
     */
    public function domainFor(Account $account): ?string
    {
        $emails = $account->contacts()
            ->whereNotNull('email')->where('email', '!=', '')
            ->orderByDesc('is_primary')->orderByDesc('is_active')
            ->pluck('email');

        foreach ($emails as $email) {
            $domain = Str::lower(trim(Str::afterLast($email, '@')));
            if ($domain && str_contains($domain, '.') && ! in_array($domain, self::FREE_MAIL, true)) {
                return $domain;
            }
        }

        return null;
    }

    /**
     * Fetch and store the logo for one account using its contact-email domain.
     *
     * @return string 'fetched' | 'no_domain' | 'failed' | 'skipped_has_logo'
     */
    public function fetch(Account $account, bool $force = false): string
    {
        if ($account->logo_path && ! $force) {
            return 'skipped_has_logo';
        }

        $domain = $this->domainFor($account);
        if (! $domain) {
            return 'no_domain';
        }

        return $this->fetchFromDomain($account, $domain, force: true);
    }

    /**
     * Fetch and store the logo for a domain given explicitly (e.g. a known
     * institution resolved by name).
     *
     * @return string 'fetched' | 'failed' | 'skipped_has_logo' | 'no_domain'
     */
    public function fetchFromDomain(Account $account, ?string $domain, bool $force = false): string
    {
        if ($account->logo_path && ! $force) {
            return 'skipped_has_logo';
        }

        $domain = $this->normalizeDomain($domain);
        if (! $domain) {
            return 'no_domain';
        }

        $image = $this->fromGoogle($domain);
        if ($image === null) {
            return 'failed';
        }

        $this->store($account, $image);

        return 'fetched';
    }

    /** Strip scheme/path/leading www so a bare host remains, or null if unusable. */
    private function normalizeDomain(?string $domain): ?string
    {
        if (! $domain) {
            return null;
        }
        $domain = Str::lower(trim($domain));
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = Str::before($domain, '/');
        $domain = preg_replace('#^www\.#', '', (string) $domain);

        return $domain && str_contains($domain, '.') ? $domain : null;
    }

    /**
     * Ask Google's favicon service for a real icon, trying the bare host, the
     * www host and the full URL — its cache does not key them the same way.
     * Returns the image bytes, or null when none of the forms yields one.
     *
     * A found icon is a 200; a miss is a 404 carrying a generic globe, so the
     * status is the filter, not the bytes.
     */
    private function fromGoogle(string $domain): ?string
    {
        foreach ([$domain, 'www.' . $domain, 'https://' . $domain] as $form) {
            try {
                $response = Http::timeout(8)->get('https://www.google.com/s2/favicons', [
                    'domain' => $form,
                    'sz'     => 256,
                ]);
            } catch (\Throwable) {
                continue;
            }

            if ($response->successful()
                && strlen($response->body()) >= 120
                && str_contains((string) $response->header('Content-Type'), 'image')) {
                return $response->body();
            }
        }

        return null;
    }

    private function store(Account $account, string $bytes): void
    {
        if ($account->logo_path) {
            Storage::disk('public')->delete($account->logo_path);
        }

        $path = "account-logos/{$account->id}/auto.png";
        Storage::disk('public')->put($path, $bytes);
        $account->update(['logo_path' => $path]);
    }
}
