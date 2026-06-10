<?php

namespace App\Models\Vendor;

use App\Models\Training\VendorPartner;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class VendorPortalUser extends Model
{
    protected $table = 'vendor_portal_users';

    protected $fillable = [
        'uuid', 'vendor_partner_id', 'user_id',
        'display_name', 'email', 'role',
        'portal_url', 'access_level', 'is_active',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
