<?php

namespace App\Models\Training;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Organization;
use App\Models\Vendor\StakeholderRole;
use App\Models\Vendor\VendorContact;
use App\Models\Vendor\VendorApproval;
use App\Models\Vendor\VendorDocument;
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
    use BelongsToOrganization, SoftDeletes, \App\Models\Concerns\RecordsAuditTrail;

    protected $table = 'vendor_partners';

    /**
     * Where the money goes is the field an attacker changes.
     *
     * The trail records THAT the bank details moved, never the values —
     * an audit log that quotes account numbers is a second copy of them.
     * PiiMasker already redacts on `account_number`; the rest are named
     * explicitly so a rename never quietly starts logging them.
     */
    protected array $auditRedact = [
        'bank_name', 'bank_branch', 'account_name', 'account_number', 'swift_code',
        /*
         * The queued change carries the PROPOSED values as JSON — including
         * the new account number. Masking the column and not this one would
         * leave the trail quoting exactly the figure the mask exists to keep
         * out of it.
         */
        'pending_change',
    ];

    // The bookkeeping around a queued change is noise; the audit entries
    // written by VendorOnboarding say who proposed and confirmed it.
    protected array $auditExclude = ['updated_at', 'pending_change_by', 'pending_change_at'];

    protected $fillable = [
        'uuid', 'organization_id',
        // Core registration
        'vendor_number', 'registration_number', 'tax_id',
        'vendor_type', 'is_oem_vendor', 'category', 'description',
        // Identity
        'name', 'party_class', 'role_details', 'primary_contact_name', 'primary_contact_designation',
        // Contact
        'contact_email', 'contact_phone', 'website_url',
        // Address
        'physical_address', 'city', 'country',
        // Partnership & fiscal
        'partnership_level', 'logo_url', 'fiscal_year_start_month',
        'renewal_anniversary_date', 'renewal_cost', 'renewal_cost_currency',
        'renewal_reminder_days',
        // Status / notes
        'notes',
        // How the supplier is paid, and when it falls due.
        'bank_name', 'bank_branch', 'account_name', 'account_number', 'swift_code',
        'payment_terms_days', 'credit_limit', 'credit_limit_currency', 'is_active',
        'onboarding_status', 'approved_at', 'approved_by', 'rejection_reason',
        'submitted_by', 'submitted_at',
        'pending_change', 'pending_change_by', 'pending_change_at',
    ];

    protected $casts = [
        'is_active'                => 'boolean',
        'is_oem_vendor'            => 'boolean',
        'approved_at'              => 'datetime',
        'renewal_anniversary_date' => 'date',
        'renewal_cost'             => 'float',
        'fiscal_year_start_month'  => 'integer',
        'renewal_reminder_days'    => 'integer',
        'submitted_at'             => 'datetime',
        'role_details'             => 'array',
        'pending_change'           => 'array',
        'pending_change_at'        => 'datetime',
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

    /**
     * The people at this vendor.
     *
     * Until now there was one name on the row and nothing else — a
     * vendor-owned task could not even name a contact (see the comment in
     * `2026_08_07_220000`). Ordered so the primary leads.
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(VendorContact::class, 'vendor_partner_id')
            ->orderByDesc('is_primary')
            ->orderBy('first_name');
    }

    /** The onboarding chain, in the order it signs. */
    public function approvals(): HasMany
    {
        return $this->hasMany(VendorApproval::class, 'vendor_partner_id')
            ->orderBy('sequence');
    }

    /** The stage waiting on somebody, or null when nothing is. */
    public function pendingApproval(): ?VendorApproval
    {
        return $this->approvals()
            ->where('status', 'pending')
            ->orderBy('sequence')
            ->first();
    }

    /** Compliance paperwork — encrypted, and expiry-tracked. */
    public function documents(): HasMany
    {
        return $this->hasMany(VendorDocument::class, 'vendor_partner_id')
            ->orderByDesc('created_at');
    }

    // ── Classification ────────────────────────────────────────────────────

    /** Every role this party has ever played, ended ones included. */
    public function stakeholderRoles(): HasMany
    {
        return $this->hasMany(StakeholderRole::class, 'vendor_partner_id')
            ->orderBy('valid_from');
    }

    /** The roles in force today — what the party IS, as opposed to was. */
    public function activeRoles(): HasMany
    {
        return $this->stakeholderRoles()->inForce();
    }

    /**
     * Does this party hold the role on the given day?
     *
     * Date-aware on purpose: "were they an approved distributor when we
     * raised that order" is the question worth being able to answer, and a
     * boolean column could never answer it.
     */
    public function hasStakeholderRole(string $role, ?string $on = null): bool
    {
        return $this->stakeholderRoles
            ->first(fn (StakeholderRole $r) => $r->role === $role && $r->isInForce($on)) !== null;
    }

    public function isIndividual(): bool
    {
        return $this->party_class === 'individual';
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
