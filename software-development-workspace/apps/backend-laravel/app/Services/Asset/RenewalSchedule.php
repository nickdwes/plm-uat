<?php

namespace App\Services\Asset;

use Carbon\CarbonImmutable;

/**
 * When somebody should hear that a renewal is coming.
 *
 * Six months, three months, one month, then weekly, then daily from a
 * fortnight out. The shape is deliberate: early warnings exist so a budget can
 * be raised and a vendor argued with, and the late ones exist because at that
 * point somebody has to actually do something today.
 *
 * Pure and stateless — given a date it says which milestone today falls on, and
 * nothing else. What has already been sent is a separate question, answered by
 * a table, because a schedule that also remembered would be two things at once
 * and testable as neither.
 */
class RenewalSchedule
{
    /** Months out at which a single warning goes. */
    private const MONTH_MILESTONES = [6, 3, 1];

    /** Inside this many days, every day. */
    private const DAILY_FROM = 14;

    /**
     * The milestone today falls on, or null for a quiet day.
     *
     * The key is what gets recorded as sent, so it has to be stable for a
     * milestone and distinct between them: `m6` once, `w:<date>` once a week,
     * `d:<date>` once a day.
     *
     * @return array{key:string, days_out:int, urgency:string}|null
     */
    public function due(CarbonImmutable $expiresOn, CarbonImmutable $today): ?array
    {
        $daysOut = (int) $today->startOfDay()->diffInDays($expiresOn->startOfDay(), false);

        /*
         * Past the date and still on the register.
         *
         * Kept daily rather than stopped. An expired certificate is a worse
         * problem than one about to expire, and a reminder that goes silent at
         * the moment it comes true is the least useful design available.
         */
        if ($daysOut < 0) {
            return ['key' => 'x:' . $today->toDateString(), 'days_out' => $daysOut, 'urgency' => 'expired'];
        }

        if ($daysOut <= self::DAILY_FROM) {
            return ['key' => 'd:' . $today->toDateString(), 'days_out' => $daysOut, 'urgency' => 'critical'];
        }

        /*
         * Monthly milestones.
         *
         * Matched on "today is on or past the milestone" rather than "today is
         * exactly the milestone", so a sweep that does not run for three days
         * still sends the warning late instead of skipping it entirely. The
         * sent-log stops it repeating.
         */
        /*
         * Nearest milestone first.
         *
         * Iterating six-then-three-then-one returns the six-month warning
         * forever, because a day that is past the three-month mark is also past
         * the six-month one. The most urgent milestone today qualifies for is
         * the one that applies.
         */
        foreach (array_reverse(self::MONTH_MILESTONES) as $months) {
            $milestone = $expiresOn->subMonths($months);

            if ($today->startOfDay()->greaterThanOrEqualTo($milestone->startOfDay())) {
                // Inside a month, the weekly rhythm takes over — but only after
                // the one-month warning itself has gone.
                if ($months === 1 && $today->startOfDay()->greaterThan($milestone->startOfDay())) {
                    break;
                }

                return [
                    'key'      => "m{$months}",
                    'days_out' => $daysOut,
                    'urgency'  => $months === 1 ? 'soon' : 'planning',
                ];
            }
        }

        /*
         * Between a month out and a fortnight out: once a week.
         *
         * Keyed on the Monday of the week rather than the day it fires, so a
         * sweep that runs twice or slips a day still sends one warning per
         * week rather than two.
         */
        if ($daysOut < 30) {
            return [
                'key'      => 'w:' . $today->startOfWeek()->toDateString(),
                'days_out' => $daysOut,
                'urgency'  => 'soon',
            ];
        }

        return null;
    }

    /**
     * Every milestone between now and expiry, for showing somebody what they
     * have signed up to receive.
     *
     * @return array<int, array{on:string, label:string}>
     */
    public function upcoming(CarbonImmutable $expiresOn, CarbonImmutable $today): array
    {
        $out = [];

        foreach (self::MONTH_MILESTONES as $months) {
            $on = $expiresOn->subMonths($months);

            if ($on->greaterThanOrEqualTo($today)) {
                $out[] = ['on' => $on->toDateString(), 'label' => "{$months} month" . ($months === 1 ? '' : 's') . ' before'];
            }
        }

        $out[] = ['on' => $expiresOn->subDays(self::DAILY_FROM)->toDateString(), 'label' => 'then daily'];

        return $out;
    }
}
