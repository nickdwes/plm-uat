<?php

namespace App\Models\Travel;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TravelRequestItem extends Model
{
    protected $fillable = [
        'travel_request_id', 'travel_expense_category_id', 'description', 'quantity',
        'rate_applied', 'policy_limit', 'exceeds_policy', 'unit',
        'amount_claimed', 'amount_approved', 'actual_cost', 'note',
    ];

    protected $casts = [
        'quantity'        => 'decimal:2',
        'rate_applied'    => 'decimal:2',
        'amount_claimed'  => 'decimal:2',
        'amount_approved' => 'decimal:2',
        'policy_limit'    => 'decimal:2',
        'actual_cost'     => 'decimal:2',
        'exceeds_policy'  => 'boolean',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(TravelRequest::class, 'travel_request_id');
    }

    public function evidence(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TravelExpenseEvidence::class, 'travel_request_item_id');
    }

    /**
     * Whether this line still needs something before the trip can close.
     *
     * A category requiring nothing is settled the moment it is approved.
     */
    public function awaitsEvidence(): bool
    {
        $required = $this->category?->evidence_type ?? 'none';

        return $required !== 'none' && ! $this->evidence()->exists();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TravelExpenseCategory::class, 'travel_expense_category_id');
    }
}
