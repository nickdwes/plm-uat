<?php

namespace App\Models\Travel;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A published round-trip bus fare for one route.
 *
 * Per route rather than per kilometre: a fare is a price, not a function of
 * distance, and computing one produces a figure no ticket office recognises.
 */
class TransportBusFare extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'travel_destination_id', 'round_trip_amount', 'currency', 'is_active',
    ];

    protected $casts = ['round_trip_amount' => 'decimal:2', 'is_active' => 'boolean'];

    protected $attributes = ['currency' => 'KES', 'is_active' => true];

    public function destination(): BelongsTo
    {
        return $this->belongsTo(TravelDestination::class, 'travel_destination_id');
    }
}
