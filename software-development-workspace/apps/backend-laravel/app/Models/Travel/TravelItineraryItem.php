<?php

namespace App\Models\Travel;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What the traveller will actually be doing, day by day.
 *
 * Separate from the costed lines because they answer different questions: an
 * approver reads the itinerary to judge whether the trip is necessary, and the
 * expenses to judge whether it is affordable.
 */
class TravelItineraryItem extends Model
{
    protected $fillable = [
        'travel_request_id', 'starts_on', 'ends_on', 'at_time',
        'activity', 'category', 'estimated_cost', 'position',
    ];

    protected $casts = [
        'starts_on'      => 'date',
        'ends_on'        => 'date',
        'estimated_cost' => 'decimal:2',
        'position'       => 'integer',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(TravelRequest::class, 'travel_request_id');
    }
}
