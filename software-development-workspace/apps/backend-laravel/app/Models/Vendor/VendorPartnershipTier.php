<?php

namespace App\Models\Vendor;

use App\Models\Training\VendorPartner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class VendorPartnershipTier extends Model
{
    protected $table = 'vendor_partnership_tiers';

    protected $fillable = [
        'uuid', 'vendor_partner_id', 'tier_name', 'tier_order',
        'revenue_target', 'revenue_currency',
        'min_certifications', 'min_headcount',
        'requirements', 'benefits', 'is_current',
    ];

    protected $casts = [
        'is_current'        => 'boolean',
        'revenue_target'    => 'float',
        'min_certifications'=> 'integer',
        'min_headcount'     => 'integer',
        'tier_order'        => 'integer',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(VendorPartner::class, 'vendor_partner_id');
    }
}
