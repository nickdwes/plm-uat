<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's place in one organisation, for a stretch of time.
 *
 * The table has existed since the schema was laid down and had no model:
 * everything reached it through a `belongsToMany` pivot or raw queries, which
 * is why the rule about what counts as current membership was written out
 * three times and agreed twice.
 *
 * A row is never deleted. Ending a placement sets `effective_to` and clears
 * `is_active`, so "who was working for Tiberbu last March" stays answerable —
 * which is the only reason to keep a date on it at all.
 */
class EmployeeOrganizationMap extends Model
{
    protected $table = 'employee_organization_map';

    protected $fillable = [
        'employee_id', 'organization_id', 'designation_id', 'department_id',
        'reports_to', 'access_scope', 'role', 'engagement_type',
        'allocation_pct', 'is_billable', 'effective_from', 'effective_to',
        'is_active', 'notes',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to'   => 'date',
        'is_active'      => 'boolean',
        'is_billable'    => 'boolean',
        'allocation_pct' => 'integer',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reports_to');
    }

    /**
     * Membership as of a date — active, started, and not yet ended.
     *
     * The flag alone is not enough. A placement that ran to March is inactive
     * in June whatever anybody remembered to tick, and one that starts next
     * month is not membership today.
     */
    public function scopeCurrent(Builder $query, ?string $on = null): Builder
    {
        $on = $on ?: now()->toDateString();

        return $query->where('is_active', true)
            ->whereDate('effective_from', '<=', $on)
            ->where(fn (Builder $q) => $q
                ->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $on));
    }

    /** End a placement without losing it. */
    public function close(string $on): void
    {
        $this->forceFill([
            'is_active'    => false,
            // A placement that ended before it began reads as data corruption.
            'effective_to' => max($on, $this->effective_from?->toDateString() ?? $on),
        ])->save();
    }
}
