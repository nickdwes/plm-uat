<?php

namespace App\Services\Support;

use App\Models\Support\Calendar;
use Carbon\CarbonImmutable;

/**
 * The slice of time a report is about, and the desk it is about.
 *
 * A month is not a range of instants until somebody says which clock. The
 * application stores UTC and the desk works Nairobi hours, so "July" asked for
 * from a browser in Nairobi and answered against UTC columns is three hours
 * wrong at both ends — enough to move tickets raised late on the 31st into
 * August and to drop the first three hours of the 1st. The SLA clock was bitten
 * by exactly this, so the conversion happens once, here, and every query takes
 * its bounds from this object rather than parsing dates of its own.
 *
 * Bounds are half-open: `from` inclusive, `until` exclusive. A closed range on
 * timestamps either double-counts the boundary second or loses it, depending on
 * which comparison somebody typed.
 */
final class ReportWindow
{
    private function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $until,
        public readonly string $timezone,
        public readonly ?int $accountId,
        public readonly ?int $teamId,
        public readonly string $label,
    ) {}

    /**
     * @param  string|null  $from   date, in the desk's timezone
     * @param  string|null  $to     date, inclusive, in the desk's timezone
     */
    public static function make(
        int $organizationId,
        ?string $from = null,
        ?string $to = null,
        ?int $accountId = null,
        ?int $teamId = null,
    ): self {
        $tz = Calendar::withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->orderByDesc('is_default')
            ->value('timezone') ?? 'Africa/Nairobi';

        $start = $from
            ? CarbonImmutable::parse($from, $tz)->startOfDay()
            : CarbonImmutable::now($tz)->startOfMonth();

        /*
         * `to` is the last day somebody wants included, so the exclusive bound
         * is the start of the day after it. Asking for the 1st to the 31st and
         * getting everything up to the 31st at midnight is the off-by-one that
         * makes a month report quietly miss its busiest day.
         */
        $end = $to
            ? CarbonImmutable::parse($to, $tz)->startOfDay()->addDay()
            : $start->addMonth();

        if ($end->lessThanOrEqualTo($start)) {
            $end = $start->addDay();
        }

        $label = $start->copy()->setTimezone($tz)->format('j M Y')
            . ' – ' . $end->copy()->setTimezone($tz)->subDay()->format('j M Y');

        return new self(
            $start->setTimezone(config('app.timezone')),
            $end->setTimezone(config('app.timezone')),
            $tz,
            $accountId,
            $teamId,
            $label,
        );
    }

    /** Days covered, for figures quoted per day. */
    public function days(): int
    {
        return max(1, (int) $this->from->diffInDays($this->until));
    }

    public function describe(): array
    {
        return [
            'from'     => $this->from->toIso8601String(),
            'until'    => $this->until->toIso8601String(),
            'timezone' => $this->timezone,
            'label'    => $this->label,
            'days'     => $this->days(),
        ];
    }
}
