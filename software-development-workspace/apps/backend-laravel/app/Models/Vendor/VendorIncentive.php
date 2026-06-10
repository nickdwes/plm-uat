<?php

namespace App\Models\Vendor;

use App\Models\Training\VendorPartner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class VendorIncentive extends Model
{
    protected $table = 'vendor_incentives';

    protected $fillable = [
        'uuid', 'vendor_partner_id',
        'name', 'incentive_type', 'description',
        'value', 'value_type', 'currency',
        'eligibility_criteria', 'start_date', 'end_date',
        'is_active',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'value'      => 'float',
        'start_date' => 'date',
        'end_date'   => 'date',
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
