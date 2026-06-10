<?php

namespace App\Models\Vendor;

use App\Models\Training\VendorPartner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class VendorProduct extends Model
{
    protected $table = 'vendor_products';

    protected $fillable = [
        'uuid', 'vendor_partner_id',
        'name', 'sku', 'product_type',
        'technology_space', 'technology_subcategory',
        'description', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $m) {
            if (empty($m->uuid)) $m->uuid = (string) Str::uuid();
        });
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(VendorPartner::class, 'vendor_partner_id');
    }
}
