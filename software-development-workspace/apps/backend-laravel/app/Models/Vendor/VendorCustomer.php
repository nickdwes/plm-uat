<?php

namespace App\Models\Vendor;

use App\Models\Training\VendorPartner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class VendorCustomer extends Model
{
    protected $table = 'vendor_customers';

    protected $fillable = [
        'uuid', 'vendor_partner_id', 'name', 'customer_type',
        'industry', 'country',
        'contact_name', 'contact_email', 'contact_phone',
        'notes', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
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

    public function projects(): HasMany
    {
        return $this->hasMany(VendorCustomerProject::class, 'vendor_customer_id');
    }
}
