<?php

namespace App\Models\Training;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Organization;
use App\Models\Vendor\VendorCustomer;
use App\Models\Vendor\VendorCustomerProject;
use App\Models\Vendor\VendorIncentive;
use App\Models\Vendor\VendorPartnershipTier;
use App\Models\Vendor\VendorPortalUser;
use App\Models\Vendor\VendorProduct;
use App\Models\Vendor\VendorProfessionalCertification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class VendorPartner extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'vendor_partners';

    protected $fillable = [
        'uuid', 'organization_id',
        // Core registration
        'vendor_number', 'registration_number', 'tax_id',
        'vendor_type', 'category', 'description',
        // Identity
        'name', 'primary_contact_name', 'primary_contact_designation',
        // Contact
        'contact_email', 'contact_phone', 'website_url',
        // Address
        'physical_address', 'city', 'country',
        // Partnership & fiscal
        'partnership_level', 'logo_url', 'fiscal_year_start_month',
        'renewal_anniversary_date', 'renewal_cost', 'renewal_cost_currency',
        'renewal_reminder_days',
        // Status / notes
        'notes', 'is_active',
        'onboarding_status', 'approved_at', 'approved_by', 'rejection_reason',
    ];

    protected $casts = [
        'is_active'                => 'boolean',
        'approved_at'              => 'datetime',
        'renewal_anniversary_date' => 'date',
        'renewal_cost'             => 'float',
        'fiscal_year_start_month'  => 'integer',
        'renewal_reminder_days'    => 'integer',
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

    // ── Relationships ────────────────────────────────────────────────────────

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Training items this vendor requires its partners to hold certifications in,
     * with the minimum headcount stored on the pivot.
     */
    public function requiredCertifications(): BelongsToMany
    {
        return $this->belongsToMany(
            TrainingItem::class,
            'vendor_required_certifications',
            'vendor_partner_id',
            'training_item_id'
        )->withPivot(['required_headcount', 'notes'])->withTimestamps();
    }

    // ── Vendor Module relationships ──────────────────────────────────────────

    public function products(): HasMany
    {
        return $this->hasMany(VendorProduct::class, 'vendor_partner_id');
    }

    public function incentives(): HasMany
    {
        return $this->hasMany(VendorIncentive::class, 'vendor_partner_id');
    }

    public function professionalCertifications(): HasMany
    {
        return $this->hasMany(VendorProfessionalCertification::class, 'vendor_partner_id');
    }

    public function partnershipTiers(): HasMany
    {
        return $this->hasMany(VendorPartnershipTier::class, 'vendor_partner_id');
    }

    public function portalUsers(): HasMany
    {
        return $this->hasMany(VendorPortalUser::class, 'vendor_partner_id');
    }

    public function customers(): HasMany
    {
        return $this->hasMany(VendorCustomer::class, 'vendor_partner_id');
    }

    public function vendorProjects(): HasMany
    {
        return $this->hasMany(VendorCustomerProject::class, 'vendor_partner_id');
    }
}
