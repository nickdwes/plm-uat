<?php

namespace App\Models\Vendor;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Training\VendorPartner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A person at a vendor.
 *
 * Deliberately the same shape as `AccountContact`: the two sides of the
 * register ask the same questions of a contact, and a second vocabulary for
 * "who do I call about an invoice" would only make reports disagree.
 */
class VendorContact extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $fillable = [
        'organization_id', 'vendor_partner_id', 'category', 'title',
        'first_name', 'last_name', 'designation', 'department',
        'email', 'phone', 'alternate_phone',
        'is_primary', 'is_active', 'created_by',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'is_active'  => 'boolean',
    ];

    protected $attributes = [
        'category'   => 'other',
        'is_primary' => false,
        'is_active'  => true,
    ];

    /**
     * What kind of contact this is.
     *
     * `sales` and `support` replace the customer list's `hr` — the people a
     * buyer actually needs at a supplier are the ones who quote, deliver and
     * fix, not the ones who hire.
     */
    public const CATEGORIES = [
        'primary', 'secondary', 'sales', 'technical', 'support', 'finance', 'other',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(VendorPartner::class, 'vendor_partner_id');
    }

    public function getFullNameAttribute(): string
    {
        return trim(collect([$this->title, $this->first_name, $this->last_name])->filter()->join(' '));
    }
}
