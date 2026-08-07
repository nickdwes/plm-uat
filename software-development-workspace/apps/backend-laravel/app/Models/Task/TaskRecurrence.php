<?php

namespace App\Models\Task;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Work that comes back round.
 *
 * The legacy had no recurrence at all — no table, no column, nothing — which is
 * why its 9,055 tasks include the same monthly reviews and certificate renewals
 * typed out by hand again and again.
 *
 * A template rather than tasks made in advance. A year of monthly tasks created
 * up front is a year of clutter in everybody's list, and every edit to the
 * template would then have to chase twelve rows that already exist.
 */
class TaskRecurrence extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'title', 'description', 'category', 'priority',
        'assigned_to', 'effort_estimate_days', 'project_id', 'account_id',
        'frequency', 'interval', 'lead_days',
        'starts_on', 'ends_on', 'is_active', 'last_generated_for', 'created_by',
    ];

    protected $casts = [
        'starts_on'          => 'date',
        'ends_on'            => 'date',
        'last_generated_for' => 'date',
        'is_active'          => 'boolean',
        'interval'           => 'integer',
        'lead_days'          => 'integer',
        'effort_estimate_days' => 'decimal:2',
    ];

    protected $attributes = [
        'category' => 'internal', 'priority' => 'medium',
        'interval' => 1, 'lead_days' => 0, 'is_active' => true,
    ];

    public const FREQUENCIES = ['daily', 'weekly', 'monthly', 'quarterly', 'yearly'];

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_to');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    /**
     * Every occurrence due on or before a given day and not yet raised.
     *
     * A list rather than a single next date, because a schedule that nobody ran
     * for three weeks owes three tasks, not one. Silently skipping the missed
     * ones is how a monthly compliance check quietly becomes a quarterly one.
     *
     * Capped, so a recurrence starting years back cannot raise hundreds of
     * tasks in one run — that is a data-entry mistake, and flooding somebody's
     * list is a worse answer to it than raising the recent ones and moving on.
     */
    public function due(CarbonImmutable $upTo, int $limit = 24): array
    {
        if (! $this->is_active) {
            return [];
        }

        $occurrence = $this->last_generated_for
            ? $this->advance(CarbonImmutable::parse($this->last_generated_for))
            : CarbonImmutable::parse($this->starts_on);

        $out = [];

        while ($occurrence->lessThanOrEqualTo($upTo) && count($out) < $limit) {
            if ($this->ends_on && $occurrence->greaterThan(CarbonImmutable::parse($this->ends_on))) {
                break;
            }

            $out[] = $occurrence;
            $occurrence = $this->advance($occurrence);
        }

        return $out;
    }

    /**
     * The next occurrence after this one.
     *
     * Quarterly is three months rather than its own unit, because a quarter is
     * not a calendar concept anybody here agrees on and "every 3 months from
     * the start date" is what people mean when they say it.
     */
    private function advance(CarbonImmutable $from): CarbonImmutable
    {
        $n = max(1, $this->interval);

        return match ($this->frequency) {
            'daily'     => $from->addDays($n),
            'weekly'    => $from->addWeeks($n),
            'monthly'   => $from->addMonthsNoOverflow($n),
            'quarterly' => $from->addMonthsNoOverflow(3 * $n),
            'yearly'    => $from->addYearsNoOverflow($n),
            default     => $from->addMonthsNoOverflow($n),
        };
    }
}
