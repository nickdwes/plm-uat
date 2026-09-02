<?php

namespace App\Models\Procurement;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Training\VendorPartner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A supplier invited to quote, and whether they came back. */
class RfqVendor extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'rfq_id', 'vendor_partner_id',
        'invited_at', 'responded_at', 'status',
    ];

    protected $casts = ['invited_at' => 'datetime', 'responded_at' => 'datetime'];

    protected $attributes = ['status' => 'invited'];

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(VendorPartner::class, 'vendor_partner_id');
    }
}
