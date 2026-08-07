<?php

namespace App\Support\Pipeline;

/**
 * The share of delivery going to an OEM's professional services.
 *
 * Shared by the estimate (what was planned) and the project (what happened),
 * so the two can be compared without either computing it differently.
 *
 * Derived, never stored. The legacy stored a ratio and it rotted: blank on 63%
 * of rows, and where present, `100` gave no way to tell whether it meant all
 * ours or all theirs. Two named quantities cannot be ambiguous in that way.
 */
final class DeliverySplit
{
    /**
     * The OEM's percentage of total effort, or null when nothing is allocated.
     *
     * Null rather than zero: no allocation recorded and an allocation of none
     * are different claims, and only one of them says we are doing all the work.
     */
    public static function oemShare(float|string|null $own, float|string|null $oem): ?int
    {
        $ownDays = (float) ($own ?? 0);
        $oemDays = (float) ($oem ?? 0);
        $total   = $ownDays + $oemDays;

        if ($own === null && $oem === null) {
            return null;
        }

        return $total <= 0 ? null : (int) round($oemDays / $total * 100);
    }

    public static function totalDays(float|string|null $own, float|string|null $oem): ?float
    {
        if ($own === null && $oem === null) {
            return null;
        }

        return (float) ($own ?? 0) + (float) ($oem ?? 0);
    }

    /**
     * How far the delivered split moved from the planned one, in points.
     *
     * The number the whole change exists to produce: a deal scoped at 20% OEM
     * and delivered at 60% either mis-scoped or lost people, and both are worth
     * knowing before the next one is quoted.
     */
    public static function drift(?int $planned, ?int $actual): ?int
    {
        return $planned === null || $actual === null ? null : $actual - $planned;
    }
}
