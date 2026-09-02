<?php

namespace App\Models\Vendor;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Training\TrainingItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One named thing a tier demands, and how many people must hold it.
 *
 * Named on purpose. "Three certifications" cannot be checked against
 * anything — three of what, held by whom? A row here points at the actual
 * training item or vendor certification, which is the same thing our people's
 * training records are keyed on, so the count is a query rather than a
 * conversation.
 */
class VendorTierRequirement extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'vendor_partnership_tier_id', 'requirement_type',
        'training_item_id', 'vendor_professional_certification_id',
        'required_headcount', 'notes',
    ];

    protected $casts = ['required_headcount' => 'integer'];

    /**
     * What kind of obligation this is.
     *
     * A sales-accreditation assessment and a week-long course are different
     * commitments, and a programme that lists both wants them told apart on
     * screen even though both are counted the same way.
     */
    public const TYPES = ['training', 'assessment', 'certification'];

    public function tier(): BelongsTo
    {
        return $this->belongsTo(VendorPartnershipTier::class, 'vendor_partnership_tier_id');
    }

    public function trainingItem(): BelongsTo
    {
        return $this->belongsTo(TrainingItem::class);
    }

    public function professionalCertification(): BelongsTo
    {
        return $this->belongsTo(VendorProfessionalCertification::class, 'vendor_professional_certification_id');
    }

    /**
     * The training item this requirement is actually counted against.
     *
     * A vendor certification is a description of an exam; what our people
     * hold is a training record, so a certification is counted through the
     * item it maps to. One that maps to nothing cannot be measured, and the
     * compliance view says so rather than reporting a silent zero.
     */
    public function countableItemId(): ?int
    {
        return $this->training_item_id
            ?? $this->professionalCertification?->training_item_id;
    }

    public function label(): string
    {
        return $this->trainingItem?->title
            ?? $this->professionalCertification?->name
            ?? 'Unnamed requirement';
    }
}
