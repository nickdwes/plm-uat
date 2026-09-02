<?php

namespace App\Support\Pipeline;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * How well a lead fits, across five dimensions.
 *
 * Deliberately not the same thing as the opportunity strength score, which
 * lives beside it on a deal. That one is ten yes-or-no indicators about a deal
 * in flight — is it registered, is the OEM pushing — and gates the commit.
 * This one asks whether the thing is worth pursuing at all.
 *
 * **Each dimension is a question with named answers, not a number.** The first
 * cut asked for a grade from 0 to 100 and got nothing: 160 leads, none of them
 * assessed, including the 80 that were converted anyway and the 9 written off.
 * The checklist on the opportunity beside it — plain answerable questions —
 * was filled in on 91 of 130 deals over the same period. The difference is not
 * diligence. Nobody can defend 70 over 75 for "solution match", so nobody
 * writes either, whereas everybody can say which of four sentences is true.
 * The points are ours to set; the answer is theirs to give.
 *
 * The overall figure is the mean of the dimensions that have been answered.
 * Unanswered ones are skipped rather than counted as zero: a lead assessed on
 * timeline alone should read as what it is, not as one good answer dragged down
 * by four questions nobody has put yet.
 *
 * Takes any record carrying the five columns, because an opportunity keeps its
 * own copy from the moment it is converted — the picture at bidding is not the
 * one that justified opening the lead, and one shared arithmetic is what stops
 * the two screens disagreeing about what 80 means.
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

    /**
     * The question each dimension actually puts, in the words someone would
     * use out loud. The label above is a column heading; this is the ask.
     */
    public const QUESTIONS = [
        'fit_company'     => 'How well does this customer fit the business we want?',
        'fit_budget'      => 'Where is the money?',
        'fit_solution'    => 'Can we deliver what they are asking for?',
        'fit_timeline'    => 'When do they need it?',
        'fit_stakeholder' => 'Who are we talking to?',
    ];

    /**
     * The answers on offer, and what each is worth.
     *
     * Four per dimension: the two that decide most cases, and the two that
     * stop the middle collapsing into whichever end the assessor prefers.
     * **Point values are unique within a dimension**, which is what lets a
     * stored score be read back as the answer that produced it — the column
     * stays an integer, so the arithmetic below, the bands, the completeness
     * and the percentile all carry on unchanged.
     *
     * Nothing here is worth more than 100 or less than 0; a dimension whose
     * worst answer is 10 rather than 0 is saying that even the bad case is not
     * quite disqualifying, and that is a judgement, not a rounding error.
     */
    public const ANSWERS = [
        'fit_company' => [
            ['value' => 'core',      'points' => 100, 'label' => 'Core market',        'help' => 'We have delivered work like this, for customers like this.'],
            ['value' => 'adjacent',  'points' => 70,  'label' => 'Adjacent',           'help' => 'Next to what we do; the reach is credible.'],
            ['value' => 'stretch',   'points' => 35,  'label' => 'A stretch',          'help' => 'We would be learning on their money.'],
            ['value' => 'outside',   'points' => 0,   'label' => 'Outside what we do', 'help' => 'Not our business, however attractive the logo.'],
        ],
        'fit_budget' => [
            ['value' => 'allocated',  'points' => 100, 'label' => 'Confirmed and allocated', 'help' => 'The money exists and has this work’s name on it.'],
            ['value' => 'indicated',  'points' => 60,  'label' => 'Indicated, not allocated', 'help' => 'A figure has been named; it is not yet committed.'],
            ['value' => 'discussion', 'points' => 30,  'label' => 'Under discussion',        'help' => 'They intend to spend. Nobody has said how much.'],
            ['value' => 'none',       'points' => 0,   'label' => 'No budget',               'help' => 'Interest without funding.'],
        ],
        'fit_solution' => [
            ['value' => 'proven',     'points' => 100, 'label' => 'Off our catalogue',   'help' => 'Something we have built and supported before.'],
            ['value' => 'configured', 'points' => 70,  'label' => 'Ours, configured',    'help' => 'Our catalogue, shaped to them.'],
            ['value' => 'partner',    'points' => 40,  'label' => 'Needs a partner',     'help' => 'We cannot complete it without an OEM or partner.'],
            ['value' => 'bespoke',    'points' => 10,  'label' => 'Bespoke build',       'help' => 'New work, estimated rather than known.'],
        ],
        'fit_timeline' => [
            ['value' => 'this_quarter', 'points' => 100, 'label' => 'This quarter',  'help' => 'A date is named and it falls in the current quarter.'],
            ['value' => 'next_quarter', 'points' => 70,  'label' => 'Next quarter',  'help' => 'Named, and far enough out to plan for.'],
            ['value' => 'this_year',    'points' => 40,  'label' => 'Later this year', 'help' => 'Real, but past the horizon we are selling into.'],
            ['value' => 'undefined',    'points' => 10,  'label' => 'No date named', 'help' => '"When we are ready" is not a timeline.'],
        ],
        'fit_stakeholder' => [
            ['value' => 'decision_maker', 'points' => 100, 'label' => 'The decision maker', 'help' => 'We are speaking to whoever signs.'],
            ['value' => 'sponsored',      'points' => 70,  'label' => 'A sponsor',         'help' => 'Someone inside who can get us to the signer.'],
            ['value' => 'influencer',     'points' => 35,  'label' => 'An influencer',     'help' => 'A voice in the room, with no route up yet.'],
            ['value' => 'unknown',        'points' => 0,   'label' => 'Nobody identified', 'help' => 'We do not know who decides this.'],
        ],
    ];

    /** The answers on offer for one dimension. */
    public static function options(string $dimension): array
    {
        return self::ANSWERS[$dimension] ?? [];
    }

    /** Every accepted answer value for one dimension, for a validation rule. */
    public static function values(string $dimension): array
    {
        return array_column(self::options($dimension), 'value');
    }

    /** What an answer is worth, or null if it is not one of ours. */
    public static function pointsFor(string $dimension, ?string $value): ?int
    {
        foreach (self::options($dimension) as $option) {
            if ($option['value'] === $value) {
                return $option['points'];
            }
        }

        return null;
    }

    /**
     * The answer a stored score came from.
     *
     * Unambiguous because the point values are unique per dimension. A score
     * that matches none of them — only possible from data written before the
     * answers existed — reads as null rather than being snapped to the nearest
     * option, because inventing which sentence somebody meant is worse than
     * admitting we no longer know.
     */
    public static function answerFor(string $dimension, ?int $points): ?array
    {
        if ($points === null) {
            return null;
        }

        foreach (self::options($dimension) as $option) {
            if ($option['points'] === $points) {
                return $option;
            }
        }

        return null;
    }

    /**
     * The dimensions still unanswered, by label.
     *
     * Used where an assessment is required, so the refusal can name what is
     * missing instead of saying "assess it first" and leaving the reader to
     * find the gap.
     *
     * @return list<string>
     */
    public static function unanswered(Model $subject): array
    {
        $answered = self::answered($subject);

        return array_values(array_diff_key(self::DIMENSIONS, $answered));
    }

    /** Null when nothing has been assessed — distinct from a score of zero. */
    public static function score(Model $subject): ?int
    {
        $answered = self::answered($subject);

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
    public static function completeness(Model $subject): int
    {
        return (int) round(count(self::answered($subject)) / count(self::DIMENSIONS) * 100);
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
    public static function percentile(Model $subject, string $table = 'leads'): ?int
    {
        $score = self::score($subject);

        if ($score === null) {
            return null;
        }

        $scores = self::assessedScores($subject->organization_id, $table);

        if (count($scores) < 5) {
            return null;
        }

        $better = count(array_filter($scores, static fn (float $s) => $s > $score));

        // "Top 15%" means 15% are better, so a leader is top 0.
        return (int) round($better / count($scores) * 100);
    }

    /** @return array<string, int> the dimensions that have a value */
    private static function answered(Model $subject): array
    {
        $out = [];

        foreach (array_keys(self::DIMENSIONS) as $key) {
            if ($subject->{$key} !== null) {
                $out[$key] = (int) $subject->{$key};
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
    private static function assessedScores(int $organizationId, string $table): array
    {
        $columns = array_keys(self::DIMENSIONS);

        // The mean of the non-null dimensions, mirroring answered() above.
        $sum   = implode(' + ', array_map(fn ($c) => "COALESCE({$c}, 0)", $columns));
        $count = implode(' + ', array_map(fn ($c) => "(CASE WHEN {$c} IS NULL THEN 0 ELSE 1 END)", $columns));

        return DB::table($table)
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->whereRaw("({$count}) > 0")
            ->selectRaw("({$sum})::numeric / ({$count}) AS mean")
            ->pluck('mean')
            ->map(fn ($m) => (float) $m)
            ->all();
    }
}
