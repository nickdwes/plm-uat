<?php

namespace App\Models\Vendor;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Training\VendorPartner;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stage's decision on one supplier.
 *
 * Materialised at submission from the organisation's configured chain, so
 * reconfiguring the stages later cannot rewrite what was actually asked of a
 * vendor last year — the same snapshot-at-submit rule the procurement,
 * travel and requisition chains follow.
 */
class VendorApproval extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'vendor_partner_id', 'stage', 'sequence',
        'status', 'decided_by', 'comment', 'decided_at',
    ];

    protected $casts = ['decided_at' => 'datetime', 'sequence' => 'integer'];

    /** Human labels, because "registration_review" is not a sentence. */
    public const STAGE_LABELS = [
        'registration_review' => 'Registration review',
        'compliance_review'   => 'Compliance review',
        'finance_review'      => 'Finance review',
        'final_approval'      => 'Final approval',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(VendorPartner::class, 'vendor_partner_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function label(): string
    {
        return self::STAGE_LABELS[$this->stage]
            ?? ucfirst(str_replace('_', ' ', $this->stage));
    }
}
