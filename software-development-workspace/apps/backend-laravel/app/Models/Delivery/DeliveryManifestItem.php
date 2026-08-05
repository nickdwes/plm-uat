<?php

namespace App\Models\Delivery;

use App\Models\Travel\TravelRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of what is in the vehicle.
 *
 * Per line rather than a free-text description, because a manifest is checked
 * off on arrival: "3 rack servers" is countable against what was received, and
 * a paragraph is not.
 */
class DeliveryManifestItem extends Model
{
    protected $fillable = [
        'travel_request_id', 'description', 'quantity', 'unit', 'weight_kg',
        'dimensions', 'reference', 'declared_value',
        'quantity_received', 'condition_on_arrival', 'notes',
    ];

    protected $casts = [
        'quantity'          => 'decimal:2',
        'quantity_received' => 'decimal:2',
        'weight_kg'         => 'decimal:2',
        'declared_value'    => 'decimal:2',
    ];

    protected $attributes = ['quantity' => 1, 'unit' => 'pcs'];

    public function request(): BelongsTo
    {
        return $this->belongsTo(TravelRequest::class, 'travel_request_id');
    }

    /**
     * Whether this line arrived in full.
     *
     * Null received is "not yet signed for", which is a different state from
     * "signed for as zero" — and the difference decides whether anyone is
     * still waiting for it.
     */
    public function isFullyReceived(): bool
    {
        return $this->quantity_received !== null
            && (float) $this->quantity_received >= (float) $this->quantity;
    }
}
