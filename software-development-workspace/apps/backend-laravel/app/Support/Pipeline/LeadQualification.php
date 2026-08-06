<?php

namespace App\Support\Pipeline;

use App\Models\Pipeline\Lead;
use Illuminate\Support\Facades\DB;

/**
 * How well a lead fits, across five dimensions.
 *
 * Deliberately not the same thing as an opportunity's score. That one is a
 * weighted checklist about a deal in flight — is the budget confirmed, is the
 * OEM pushing. This one asks whether the lead is worth pursuing at all, and it
 * is graded rather than ticked, because "does the solution match" is not a
 * yes-or-no question and pretending it is throws away what everyone already
 * knows.
 *
 * The overall figure is the mean of the dimensions that have been answered.
 * Unanswered ones are skipped rather than counted as zero: a lead assessed on
 * timeline alone should read as what it is, not as one good score dragged down
 * by four blanks nobody has looked at yet.
 */
final class LeadQualification
{
    public const DIMENSIONS = [
        'fit_company'     => 'Company Fit',
        'fit_budget'      => 'Budget Alignment',
        'fit_solution'    => 'Solution Match',
        'fit_timeline'    => 'Timeline Fit',
        'fit_stakeholder' => 'Stakeholder Access',
    ];

    /** Null when nothing has been assessed — distinct from a score of zero. */
    public static function score(Lead $lead): ?int
    {
        $answered = self::answered($lead);

        return $answered === [] ? null : (int) round(array_sum($answered) / count($answered));
    }

    public static function band(?int $score): ?string
    {
        return match (true) {
            $score === null => null,
            $score >= 80    => 'high_fit',
            $score >= 60    => 'moderate_fit',
            $score >= 40    => 'low_fit',
            default         => 'poor_fit',
        };
    }

    /**
     * How complete the assessment is.
     *
     * Shown alongside the score because 90 from one dimension and 90 from five
     * are not the same claim, and only one of them is worth acting on.
     */
    public static function completeness(Lead $lead): int
    {
        return (int) round(count(self::answered($lead)) / count(self::DIMENSIONS) * 100);
    }

    /**
     * Where this lead sits against the others, as "top N%".
     *
     * Counted across leads that have actually been assessed. Ranking a lead
     * against a pile of unassessed ones would flatter it: being better than
     * something nobody has looked at is not an achievement.
     *
     * Returns null when there is nothing to compare against — a percentile
     * drawn from two leads is noise dressed as a statistic.
     */
    public static function percentile(Lead $lead): ?int
    {
        $score = self::score($lead);

        if ($score === null) {
            return null;
        }

        $scores = self::assessedScores($lead->organization_id);

        if (count($scores) < 5) {
            return null;
        }

        $better = count(array_filter($scores, static fn (float $s) => $s > $score));

        // "Top 15%" means 15% are better, so a leader is top 0.
        return (int) round($better / count($scores) * 100);
    }

    /** @return array<string, int> the dimensions that have a value */
    private static function answered(Lead $lead): array
    {
        $out = [];

        foreach (array_keys(self::DIMENSIONS) as $key) {
            if ($lead->{$key} !== null) {
                $out[$key] = (int) $lead->{$key};
            }
        }

        return $out;
    }

    /**
     * Every assessed lead's mean, computed in the database.
     *
     * In SQL rather than by loading every lead, because this runs on a screen
     * that shows one lead and would otherwise read the whole book to do it.
     */
    private static function assessedScores(int $organizationId): array
    {
        $columns = array_keys(self::DIMENSIONS);

        // The mean of the non-null dimensions, mirroring answered() above.
        $sum   = implode(' + ', array_map(fn ($c) => "COALESCE({$c}, 0)", $columns));
        $count = implode(' + ', array_map(fn ($c) => "(CASE WHEN {$c} IS NULL THEN 0 ELSE 1 END)", $columns));

        return DB::table('leads')
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->whereRaw("({$count}) > 0")
            ->selectRaw("({$sum})::numeric / ({$count}) AS mean")
            ->pluck('mean')
            ->map(fn ($m) => (float) $m)
            ->all();
    }
}
