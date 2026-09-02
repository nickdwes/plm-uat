<?php

namespace App\Models\Training;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Department;
use App\Models\Organization;
use App\Models\Vendor\VendorProduct;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class TrainingItem extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'training_items';

    protected $fillable = [
        'uuid', 'training_code', 'organization_id', 'title', 'training_version',
        'description', 'type', 'delivery', 'level',
        'duration_hours', 'duration_minutes', 'is_long_course',
        'provider_name', 'provider_url', 'cost_currency', 'estimated_cost',
        'training_provider_type', 'certifying_body_id', 'vendor_partner_id',
        'tags', 'is_active', 'is_featured', 'max_enrollments', 'thumbnail_url',
        'requires_approval', 'is_budgeted', 'budget_amount',
        'is_technical', 'requires_proctored_exam',
        'certificate_issued', 'certification_name', 'exam_code', 'exam_duration',
        'passing_score', 'exam_provider', 'validity_years', 'renewal_required', 'cpe_credits',
    ];

    protected $casts = [
        'tags'                     => 'array',
        'is_active'                => 'boolean',
        'is_featured'              => 'boolean',
        'is_long_course'           => 'boolean',
        'requires_approval'        => 'boolean',
        'is_budgeted'              => 'boolean',
        'is_technical'             => 'boolean',
        'requires_proctored_exam'  => 'boolean',
        'certificate_issued'       => 'boolean',
        'renewal_required'         => 'boolean',
        'estimated_cost'           => 'decimal:2',
        'budget_amount'            => 'decimal:2',
        'duration_hours'           => 'integer',
        'duration_minutes'         => 'integer',
        'max_enrollments'          => 'integer',
        'passing_score'            => 'integer',
        'validity_years'           => 'integer',
        'cpe_credits'              => 'integer',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            // Auto-compute is_long_course
            $model->is_long_course = $model->duration_hours > 8;
        });
        static::updating(function (self $model) {
            $model->is_long_course = $model->duration_hours > 8;
        });
    }

    // ── Relationships ──────────────────────────────────────────────────────────

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function competencies(): BelongsToMany
    {
        return $this->belongsToMany(
            CompetencyItem::class,
            'training_item_competencies',
            'training_item_id',
            'competency_item_id'
        );
    }

    public function trainingRecords(): HasMany
    {
        return $this->hasMany(EmployeeTrainingRecord::class, 'training_item_id');
    }

    public function certifyingBody(): BelongsTo
    {
        return $this->belongsTo(TrainingCertifyingBody::class, 'certifying_body_id');
    }

    public function vendorPartner(): BelongsTo
    {
        return $this->belongsTo(VendorPartner::class, 'vendor_partner_id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(
            VendorProduct::class,
            'training_item_products',
            'training_item_id',
            'vendor_product_id'
        );
    }

    /**
     * The vendors whose partnership tier this training counts toward — the
     * same pivot Vendor Compliance already reads from the vendor's side
     * (VendorPartner::requiredCertifications()), just walked the other way.
     * One source of truth: a requirement set from the training item form and
     * one set from the Vendor Compliance screen are the same table.
     */
    public function requiredByVendors(): BelongsToMany
    {
        return $this->belongsToMany(
            VendorPartner::class,
            'vendor_required_certifications',
            'training_item_id',
            'vendor_partner_id'
        )->withPivot(['required_headcount', 'notes'])->withTimestamps();
    }
}
