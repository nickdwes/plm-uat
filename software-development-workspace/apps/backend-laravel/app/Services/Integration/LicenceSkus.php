<?php

namespace App\Services\Integration;

/**
 * Telling a licence you pay for from one you merely have.
 *
 * ── Why this exists ──
 *
 * Graph reports every SKU in the tenant the same way, paid or not, and the
 * free ones carry nominal seat counts: WINDOWS_STORE and FORMS_PRO each show a
 * million prepaid seats, POWER_BI_STANDARD another million, the viral and
 * developer plans ten thousand apiece.
 *
 * Summed naively that is over three million seats against a couple of hundred
 * in use, and "seat utilisation" renders as 0% across a perfectly healthy
 * estate. Every number the software dashboard exists to show — utilisation,
 * spare seats, spend at risk — is meaningless unless these are set aside
 * first.
 *
 * ── What this is not ──
 *
 * Microsoft does not publish paid-ness through Graph. There is no field to
 * read; `subscribedSkus` carries no price and no commercial flag. So this is a
 * heuristic, and it is written to be visibly one: the command prints the bucket
 * it put every SKU in, and `config/ms-licence-skus.php` overrides any call it
 * gets wrong. A wrong guess should be a line of config, not a code change.
 */
class LicenceSkus
{
    public const PAID  = 'paid';
    public const FREE  = 'free';
    public const TRIAL = 'trial';

    /**
     * SKUs Microsoft gives away, by part number.
     *
     * Named individually rather than inferred, because these are the ones that
     * distort the arithmetic most and a name is easier to argue with than a
     * threshold.
     */
    private const KNOWN_FREE = [
        'WINDOWS_STORE',
        'FORMS_PRO',
        'POWER_BI_STANDARD',
        'POWERAPPS_VIRAL',
        'POWERAPPS_DEV',
        'FLOW_FREE',
        'STREAM',
        'TEAMS_EXPLORATORY',
        'TEAMS_FREE',
        'CCIBOTS_PRIVPREV_VIRAL',
        'RIGHTSMANAGEMENT_ADHOC',
        'MCOPSTNC',
    ];

    /** Fragments that mark a trial, a pilot or a partner sandbox. */
    private const TRIAL_MARKERS = ['VIRAL', 'TRIAL', 'SANDBOX', 'PREVIEW', 'PRIVPREV', '_DEV'];

    /**
     * Above this, a seat count is a nominal ceiling rather than a purchase.
     *
     * A backstop for free SKUs not named above, not the primary test — an
     * organisation genuinely buying ten thousand seats would be misread, which
     * is why the override file exists and why the command shows its working.
     */
    private const NOMINAL_SEATS = 10_000;

    /**
     * Readable names for the part numbers Graph returns.
     *
     * `SPE_E3` and `O365_BUSINESS_ESSENTIALS` mean nothing to whoever reads the
     * register. Microsoft publishes this mapping as a downloadable CSV and not
     * as an endpoint, so it is a table we keep — covering what is actually in
     * the tenant, and falling back to a tidied part number for the rest rather
     * than inventing a name.
     */
    private const NAMES = [
        'AAD_PREMIUM'                    => 'Entra ID P1',
        'AAD_PREMIUM_P2'                 => 'Entra ID P2',
        'DYN365_BUSCENTRAL_ESSENTIAL'    => 'Dynamics 365 Business Central Essentials',
        'DYN365_BUSCENTRAL_PREMIUM'      => 'Dynamics 365 Business Central Premium',
        'DYN365_BUSINESS_MARKETING'      => 'Dynamics 365 Marketing',
        'EXCHANGESTANDARD'               => 'Exchange Online (Plan 1)',
        'EXCHANGEENTERPRISE'             => 'Exchange Online (Plan 2)',
        'FORMS_PRO'                      => 'Dynamics 365 Customer Voice',
        'MDATP_XPLAT'                    => 'Defender for Endpoint',
        'O365_BUSINESS_ESSENTIALS'       => 'Microsoft 365 Business Basic',
        'O365_BUSINESS_PREMIUM'          => 'Microsoft 365 Business Standard',
        'PBI_PREMIUM_PER_USER'           => 'Power BI Premium (per user)',
        'POWER_BI_STANDARD'              => 'Power BI (free)',
        'POWERAPPS_PER_USER'             => 'Power Apps (per user)',
        'POWERAUTOMATE_ATTENDED_RPA'     => 'Power Automate Premium (attended RPA)',
        'SPE_E3'                         => 'Microsoft 365 E3',
        'SPE_E5'                         => 'Microsoft 365 E5',
        'VIRTUAL_AGENT_BASE'             => 'Copilot Studio',
        'VISIOCLIENT'                    => 'Visio Plan 2',
        'VIVA'                           => 'Microsoft Viva Suite',
        'WINDOWS_STORE'                  => 'Windows Store for Business',
    ];

    /** paid, free or trial. */
    public function classify(string $partNumber, int $prepaidSeats): string
    {
        $key = strtoupper(trim($partNumber));

        if (in_array($key, self::KNOWN_FREE, true)) {
            return self::FREE;
        }

        foreach (self::TRIAL_MARKERS as $marker) {
            if (str_contains($key, $marker)) {
                return self::TRIAL;
            }
        }

        return $prepaidSeats >= self::NOMINAL_SEATS ? self::FREE : self::PAID;
    }

    /** Does this SKU belong in utilisation and spend arithmetic? */
    public function isCounted(string $partNumber, int $prepaidSeats): bool
    {
        return $this->classify($partNumber, $prepaidSeats) === self::PAID;
    }

    /**
     * A name somebody can read.
     *
     * Falls back to the part number with its separators softened rather than
     * guessing. A wrong friendly name is worse than a cryptic accurate one,
     * because only one of the two makes somebody go and check.
     */
    public function name(string $partNumber): string
    {
        $key = strtoupper(trim($partNumber));

        if (isset(self::NAMES[$key])) {
            return self::NAMES[$key];
        }

        // Graph occasionally returns part numbers containing zero-width
        // characters, which make two identical-looking names fail to match.
        $clean = preg_replace('/[^\PC\s]/u', '', $partNumber) ?? $partNumber;

        return trim(str_replace('_', ' ', $clean));
    }
}
