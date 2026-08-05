<?php

namespace App\Models\Travel;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One journey, by one mode.
 *
 * A leg rather than a field on the request, because a real trip is several: a
 * flight to Kampala and taxis once there. A single transport_mode column
 * forces a choice that does not exist.
 */
class TravelTransportLeg extends Model
{
    public const TRAVEL_TYPES = ['local', 'international', 'delivery'];

    protected $fillable = [
        'travel_request_id', 'mode', 'purpose', 'travel_type',
        'from_location', 'to_location', 'travel_destination_id',
        'estimated_km', 'actual_km', 'rate_per_km_applied',
        'fuel_price_per_litre', 'transport_vehicle_type_id', 'days',
        'is_delivery', 'item_description', 'item_weight_kg', 'item_dimensions',
        'estimated_cost', 'actual_cost', 'currency', 'notes',
    ];

    protected $casts = [
        'estimated_km'         => 'decimal:2',
        'actual_km'            => 'decimal:2',
        'rate_per_km_applied'  => 'decimal:2',
        'fuel_price_per_litre' => 'decimal:2',
        'item_weight_kg'       => 'decimal:2',
        'estimated_cost'       => 'decimal:2',
        'actual_cost'          => 'decimal:2',
        'is_delivery'          => 'boolean',
        'days'                 => 'integer',
    ];

    protected $attributes = ['travel_type' => 'local', 'is_delivery' => false, 'currency' => 'KES'];

    public function request(): BelongsTo
    {
        return $this->belongsTo(TravelRequest::class, 'travel_request_id');
    }

    public function vehicleType(): BelongsTo
    {
        return $this->belongsTo(TransportVehicleType::class, 'transport_vehicle_type_id');
    }
}
