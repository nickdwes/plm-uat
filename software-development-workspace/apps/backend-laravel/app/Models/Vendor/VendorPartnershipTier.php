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
        'uuid', 'organization_id', 'vendor_partner_id', 'tier_name', 'tier_order',
        'scope', 'vendor_product_id',
        'revenue_target', 'revenue_currency', 'seats_required', 'seats_activated',
        'min_certifications', 'min_headcount',
        'requirements', 'benefits', 'is_current', 'valid_from', 'valid_until',
    ];

    protected $casts = [
        'is_current'        => 'boolean',
        'revenue_target'    => 'float',
        'min_certifications'=> 'integer',
        'min_headcount'     => 'integer',
        'tier_order'        => 'integer',
        'seats_required'    => 'integer',
        'seats_activated'   => 'integer',
        'valid_from'        => 'date',
        'valid_until'       => 'date',
    ];

    /** Whether this tier covers the partnership generally or one product. */
    public const SCOPES = ['general', 'product'];

    /**
     * A commercial commitment with seats behind it.
     *
     * The seat count is what makes a revenue target checkable: "half a
     * million dollars" is a sentence, "half a million dollars covering 250
     * activated seats, of which 180 are live" is a status.
     */
    public function hasMonetaryRequirement(): bool
    {
        return $this->revenue_target !== null && $this->revenue_target > 0;
    }

    /** In force on the given day. Null dates mean nobody has said. */
    public function isInForce(?string $on = null): bool
    {
        $on = $on ? \Illuminate\Support\Carbon::parse($on) : now();

        if ($this->valid_from === null) {
            return (bool) $this->is_current;
        }

        return $this->valid_from->lte($on)
            && ($this->valid_until === null || $this->valid_until->gte($on));
    }

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

    /** The product this tier is held on, when it is not a general one. */
    public function product(): BelongsTo
    {
        return $this->belongsTo(VendorProduct::class, 'vendor_product_id');
    }

    /** What has to be true for us to still hold this tier. */
    public function tierRequirements(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(VendorTierRequirement::class, 'vendor_partnership_tier_id');
    }
}
