<?php

namespace App\Models\Asset;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One term this asset has been through.
 *
 * `expires_on` says when the current term ends. This says what the last one
 * cost and who sold it to us — the questions asked when the invoice lands.
 */
class AssetRenewal extends Model
{
    protected $table = 'asset_renewals';

    protected $fillable = [
        'organization_id', 'asset_id', 'renewed_on', 'expires_on', 'cost', 'currency',
        'vendor_partner_id', 'vendor_name', 'seats', 'invoice_number', 'notes', 'recorded_by',
    ];

    protected $casts = ['renewed_on' => 'date', 'expires_on' => 'date', 'cost' => 'decimal:2'];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
