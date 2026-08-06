<?php

namespace App\Support\Pipeline;

use App\Models\Pipeline\Opportunity;
use Illuminate\Support\Carbon;

/**
 * How good an opportunity looks, out of 80.
 *
 * The weights are the legacy's, unchanged — they encode how Konvergenz reads a
 * deal, and changing them would silently re-rank a pipeline people already
 * have opinions about. What is not carried over is the arithmetic, which was
 * wrong in three ways:
 *
 *   The current quarter was computed as ceil((month - 1) / 3) while the close
 *   quarter used ceil(month / 3). Two different bases, so the comparison was
 *   off by one for most of the year and a deal closing this quarter often
 *   scored as though it closed next.
 *
 *   The branch for "two quarters out" had no break and fell through, quietly
 *   awarding 10 where the table says 5.
 *
 *   The banding used `switch ($score) { case ($score <= 29): ... }`, which
 *   compares the score against a boolean rather than testing it. Every band
 *   was decided by a type juggle.
 *
 * Computed rather than stored. It depends on today's date through the closing
 * quarter, so a stored copy is stale the moment the quarter turns — which is
 * exactly when somebody is looking at the pipeline.
 */
final class OpportunityScore
{
    private const WEIGHTS = [
        'account_weight'      => ['category_1' => 15, 'category_2' => 10, 'category_3' => 5],
        'quarters_out'        => [0 => 15, 1 => 10, 2 => 5],
        'knows_procurement'   => 10,
        'has_budget'          => 5,
        'deal_registered'     => 5,
        'compliance_pressure' => 15,
        'oem_pressure'        => 5,
        'prior_deal_closed'   => 10,
    ];

    public const MAX = 80;

    public static function for(Opportunity $o, ?Carbon $asOf = null): int
    {
        $asOf = $asOf ?? Carbon::now();
        $score = 0;

        $score += self::WEIGHTS['account_weight'][$o->account_weight] ?? 0;

        if ($o->expected_close_date) {
            // Both quarters on the same basis, which is the fix.
            $quartersOut = (self::quarter($o->expected_close_date) - self::quarter($asOf))
                + (($o->expected_close_date->year - $asOf->year) * 4);

            // A deal already past its close date scores nothing here: it is
            // late, not imminent.
            $score += self::WEIGHTS['quarters_out'][$quartersOut] ?? 0;
        }

        // "Do we know how they buy" — worth more than any single alignment.
        if ($o->knows_procurement)   $score += self::WEIGHTS['knows_procurement'];
        if ($o->amount > 0)          $score += self::WEIGHTS['has_budget'];
        if ($o->deal_registered)     $score += self::WEIGHTS['deal_registered'];
        if ($o->compliance_pressure) $score += self::WEIGHTS['compliance_pressure'];
        if ($o->oem_pressure)        $score += self::WEIGHTS['oem_pressure'];
        if ($o->prior_deal_closed)   $score += self::WEIGHTS['prior_deal_closed'];

        return $score;
    }

    /**
     * The band, as the legacy's pipeline report meant to group them.
     *
     * Boundaries taken from the report's own labels rather than from its code,
     * which contradicted them: it was labelled "between 30 and 49" while
     * testing 30 to 59.
     */
    public static function band(int $score): string
    {
        return match (true) {
            $score >= 65 => 'strong',
            $score >= 50 => 'promising',
            $score >= 30 => 'developing',
            default      => 'weak',
        };
    }

    private static function quarter(Carbon $date): int
    {
        return (int) ceil($date->month / 3);
    }
}
