<?php

namespace App\Support\Pipeline;

use App\Models\Pipeline\Opportunity;

/**
 * Ten strength indicators, ten points each, sixty-five to commit.
 *
 * This replaces the legacy's weighted score, and the change is deliberate
 * rather than a simplification for its own sake. Three reasons:
 *
 *   The indicators are unchanged. All ten are the legacy's own booleans —
 *   know procurement, deal registered, compliance pressure, OEM pressure,
 *   prior deal closed, weekly meetings, and the four alignments. Nothing has
 *   been invented and nothing dropped.
 *
 *   The legacy's weights could not be explained by anyone still here. It paid
 *   15 for compliance pressure and 5 for OEM pressure with no recorded reason,
 *   and folded in the account's category and the closing quarter, so a deal's
 *   score moved when nobody had touched it. A score that changes on its own is
 *   one nobody trusts.
 *
 *   Sixty-five is now a gate rather than a report heading. The legacy computed
 *   bands for a dashboard and did nothing with them; here the score decides
 *   whether a deal may be committed, which is the first time the number has
 *   had to be defensible.
 *
 * What is lost is the weighting — compliance pressure now counts the same as
 * an OEM relationship. That is worth knowing, and worth revisiting once
 * somebody can say what the weights should be.
 */
final class OpportunityScore
{
    /**
     * The ten, in the order the Processing screen lists them.
     *
     * @var array<string, array{label: string, help: string}>
     */
    public const INDICATORS = [
        'knows_procurement' => [
            'label' => 'Know Procurement / CTO',
            'help'  => 'Confirm if we know the Procurement Manager or Chief Technology Officer.',
        ],
        'deal_registered' => [
            'label' => 'Deal Registered',
            'help'  => 'Verify that the deal has been officially registered within our internal systems.',
        ],
        'compliance_pressure' => [
            'label' => 'Compliance Pressure',
            'help'  => 'Determine if the client is facing industry regulations or internal policy changes.',
        ],
        'oem_pressure' => [
            'label' => 'OEM Pressure',
            'help'  => 'Ascertain if the client is being pushed by their Original Equipment Manufacturer.',
        ],
        'prior_deal_closed' => [
            'label' => 'Prior Deal Closed',
            'help'  => 'Verify if a previous business agreement has been successfully concluded.',
        ],
        'discuss_weekly' => [
            'label' => 'Weekly Meetings',
            'help'  => 'Check if regular sync meetings are scheduled with the client team.',
        ],
        'alignment_with_customer' => [
            'label' => 'Customer Alignment',
            'help'  => "Confirm that our solutions align with the client's long-term strategic goals.",
        ],
        'alignment_with_oem_financials' => [
            'label' => 'OEM Financial Alignment',
            'help'  => "Confirm that the client's budget and financial expectations align with ours.",
        ],
        'alignment_with_oem_solution' => [
            'label' => 'OEM Solution Alignment',
            'help'  => 'Confirm the proposed solution matches what the OEM can actually deliver.',
        ],
        'alignment_with_oem_support' => [
            'label' => 'OEM Support Alignment',
            'help'  => 'Confirm the OEM will support the solution over the life of the contract.',
        ],
    ];

    public const POINTS_EACH   = 10;
    public const MAX           = 100;

    /** The score a deal must reach before it can be committed. */
    public const COMMIT_THRESHOLD = 65;

    public static function for(Opportunity $opportunity): int
    {
        $met = 0;

        foreach (array_keys(self::INDICATORS) as $key) {
            if ($opportunity->{$key}) {
                $met++;
            }
        }

        return $met * self::POINTS_EACH;
    }

    public static function meetsCommitThreshold(Opportunity $opportunity): bool
    {
        return self::for($opportunity) >= self::COMMIT_THRESHOLD;
    }

    /**
     * The band, kept for colour on screen.
     *
     * Boundaries chosen around the commit threshold rather than inherited: the
     * only one that means anything operationally is 65, so the others sit
     * either side of it at even intervals.
     */
    public static function band(int $score): string
    {
        return match (true) {
            $score >= self::COMMIT_THRESHOLD => 'strong',
            $score >= 40                     => 'developing',
            default                          => 'weak',
        };
    }

    /** Which indicators are still unmet, so a screen can say what to go and do. */
    public static function outstanding(Opportunity $opportunity): array
    {
        $out = [];

        foreach (self::INDICATORS as $key => $meta) {
            if (! $opportunity->{$key}) {
                $out[] = ['key' => $key, 'label' => $meta['label']];
            }
        }

        return $out;
    }
}
