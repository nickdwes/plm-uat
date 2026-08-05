<?php

namespace App\Models\Travel;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * What was actually booked, as opposed to what was estimated.
 *
 * Kept apart from the request's costed lines on purpose: the estimate is what
 * an approver agreed to, the booking is what was bought. Collapsing them would
 * overwrite the figure the approval was given against, and "approved 28,000,
 * booked 27,800" would stop being answerable.
 */
class TravelBooking extends Model
{
    use SoftDeletes;

    public const TYPES = ['flight', 'hotel', 'transport', 'other'];

    protected $fillable = [
        'travel_request_id', 'booking_type', 'vendor', 'status', 'reference',
        'from_location', 'to_location', 'booking_date', 'total_cost', 'currency', 'created_by',
    ];

    protected $casts = [
        'booking_date' => 'date',
        'total_cost'   => 'decimal:2',
    ];

    protected $attributes = ['status' => 'pending', 'currency' => 'KES'];

    public function request(): BelongsTo
    {
        return $this->belongsTo(TravelRequest::class, 'travel_request_id');
    }
}
