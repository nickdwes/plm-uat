<?php

namespace App\Models\Travel;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * A class of vehicle, and what it consumes.
 *
 * Consumption is held per class rather than per vehicle: real figures vary
 * more by driver and load than by chassis, and an organisation that cannot
 * maintain per-vehicle data ends up with one stale row per car instead of one
 * good row per class.
 */
class TransportVehicleType extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'name', 'km_per_litre', 'load_capacity_kg', 'is_active',
    ];

    protected $casts = [
        'km_per_litre'     => 'decimal:2',
        'load_capacity_kg' => 'decimal:2',
        'is_active'        => 'boolean',
    ];

    protected $attributes = ['is_active' => true];
}
