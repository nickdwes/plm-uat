<?php

namespace App\Models\Pipeline;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a phase of the work is expected to cost and earn.
 *
 * Profit is stored rather than computed from revenue minus cost. The legacy's
 * figures frequently did not reconcile, and that is not a data-quality problem
 * to be corrected: a quoted profit is negotiated, and computing it would
 * silently rewrite what somebody agreed with a customer.
 */
class OpportunityEstimate extends Model
{
    use BelongsToOrganization;

    protected $table = 'opportunity_estimates';

    protected $fillable = [
        'organization_id', 'opportunity_id', 'name',
        'budget', 'revenue', 'cost', 'profit', 'created_by',

        // How much of the delivery we take and how much goes to the OEM.
        'vendor_partner_id', 'delivery_days_own', 'delivery_days_oem', 'oem_ps_cost',
    ];

    protected $casts = [
        'budget'  => 'decimal:2',
        'revenue' => 'decimal:2',
        'cost'    => 'decimal:2',
        'profit'  => 'decimal:2',
        'delivery_days_own' => 'decimal:1',
        'delivery_days_oem' => 'decimal:1',
        'oem_ps_cost'       => 'decimal:2',
    ];

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(OpportunityDocument::class, 'estimate_id');
    }

    /** Whose professional services take the OEM share. */
    public function vendorPartner(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Training\VendorPartner::class, 'vendor_partner_id');
    }

    /**
     * The OEM's share of effort, derived from two named quantities.
     *
     * Never stored — the legacy stored a ratio and nobody could tell which side
     * `100` referred to.
     */
    public function getOemShareAttribute(): ?int
    {
        return \App\Support\Pipeline\DeliverySplit::oemShare($this->delivery_days_own, $this->delivery_days_oem);
    }

    public function getTotalDeliveryDaysAttribute(): ?float
    {
        return \App\Support\Pipeline\DeliverySplit::totalDays($this->delivery_days_own, $this->delivery_days_oem);
    }

    /**
     * What the arithmetic would have said, for comparison only.
     *
     * Surfaced so a figure that does not add up is visible rather than
     * corrected — the screens can flag it and let a person decide.
     */
    public function getImpliedProfitAttribute(): ?string
    {
        if ($this->revenue === null || $this->cost === null) {
            return null;
        }

        return bcsub((string) $this->revenue, (string) $this->cost, 2);
    }
}
