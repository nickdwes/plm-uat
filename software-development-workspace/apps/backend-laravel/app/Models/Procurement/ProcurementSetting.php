<?php

namespace App\Models\Procurement;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * The organisation's procurement policy dials: the margin floor a purchase may
 * not erode without executive sign-off, and how far an invoice may drift from
 * its purchase order before the three-way match calls it an exception.
 */
class ProcurementSetting extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'margin_floor_percent', 'price_match_tolerance_percent', 'currency',
    ];

    protected $casts = [
        'margin_floor_percent'          => 'decimal:2',
        'price_match_tolerance_percent' => 'decimal:2',
    ];

    protected $attributes = ['price_match_tolerance_percent' => 2, 'currency' => 'KES'];

    /** One row per organisation, made on first read. */
    public static function forOrganization(int $organizationId): self
    {
        return static::firstOrCreate(['organization_id' => $organizationId]);
    }
}
