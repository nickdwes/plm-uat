<?php

namespace App\Models\Travel;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One cell of the allowance rate card.
 *
 * A null `job_grade_id` or `travel_destination_id` means "applies to all",
 * not "unset" — which is what lets an organisation state one meals rate
 * globally and override it for two expensive cities, rather than enumerating
 * every grade against every destination.
 */
class TravelAllowanceRate extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'travel_expense_category_id', 'job_grade_id',
        'travel_destination_id', 'amount', 'currency', 'unit',
        'effective_from', 'effective_to',
    ];

    protected $casts = [
        'amount'         => 'decimal:2',
        'effective_from' => 'date',
        'effective_to'   => 'date',
    ];

    protected $attributes = ['currency' => 'KES', 'unit' => 'per_day'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(TravelExpenseCategory::class, 'travel_expense_category_id');
    }

    /** Rows in force on a given date. */
    public function scopeEffectiveOn(Builder $q, $date): Builder
    {
        return $q->whereDate('effective_from', '<=', $date)
                 ->where(fn ($w) => $w->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date));
    }

    /**
     * Order candidates so the most specific rate wins.
     *
     * grade + destination, then destination, then grade, then the default.
     * Ordering in SQL rather than filtering means a request never falls
     * through to "no rate" when a specific one is absent.
     */
    public function scopeMostSpecificFirst(Builder $q): Builder
    {
        return $q->orderByRaw(
            '(CASE WHEN job_grade_id IS NOT NULL AND travel_destination_id IS NOT NULL THEN 3'
            . ' WHEN travel_destination_id IS NOT NULL THEN 2'
            . ' WHEN job_grade_id IS NOT NULL THEN 1 ELSE 0 END) DESC'
        )->orderByDesc('effective_from');
    }
}
