<?php

namespace App\Models\Vendor;

use App\Models\Training\VendorPartner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class VendorCustomerProject extends Model
{
    protected $table = 'vendor_customer_projects';

    protected $fillable = [
        'uuid', 'vendor_partner_id', 'vendor_customer_id',
        'name', 'project_code', 'status',
        'technology_space', 'technology_subcategory',
        'description', 'start_date', 'end_date',
        'contract_value', 'currency', 'notes',
    ];

    protected $casts = [
        'start_date'     => 'date',
        'end_date'       => 'date',
        'contract_value' => 'float',
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

    public function customer(): BelongsTo
    {
        return $this->belongsTo(VendorCustomer::class, 'vendor_customer_id');
    }
}
