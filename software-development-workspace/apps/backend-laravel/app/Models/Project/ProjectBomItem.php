<?php

namespace App\Models\Project;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Training\VendorPartner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One priced line of a project's Bill of Materials. `margin` and `order_value`
 * are derived from quantity × price so they never drift from the figures typed.
 */
class ProjectBomItem extends Model
{
    use BelongsToOrganization;

    protected $table = 'project_bom_items';

    /** The nine fulfilment categories the spec classifies a line into. */
    public const CATEGORIES = [
        'hardware', 'software', 'cloud_subscription', 'professional_services',
        'support_services', 'training', 'travel_accommodation', 'logistics_cost',
        'third_party_services',
    ];

    protected $fillable = [
        'organization_id', 'project_bom_id', 'project_id', 'line_no',
        'category', 'description', 'part_number', 'vendor_partner_id', 'supplier_name',
        'quantity', 'unit', 'unit_cost', 'selling_price', 'currency',
        'expected_delivery_date', 'phase_id', 'notes',
    ];

    protected $casts = [
        'line_no'                => 'integer',
        'quantity'               => 'decimal:2',
        'unit_cost'              => 'decimal:2',
        'selling_price'          => 'decimal:2',
        'expected_delivery_date' => 'date',
    ];

    protected $appends = ['margin', 'order_value'];

    public function bom(): BelongsTo
    {
        return $this->belongsTo(ProjectBom::class, 'project_bom_id');
    }

    public function vendorPartner(): BelongsTo
    {
        return $this->belongsTo(VendorPartner::class, 'vendor_partner_id');
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(ProjectPhase::class, 'phase_id');
    }

    /** (sell − cost) × quantity. */
    public function getMarginAttribute(): float
    {
        return round(((float) $this->selling_price - (float) $this->unit_cost) * (float) $this->quantity, 2);
    }

    /** sell × quantity — the order value this line represents. */
    public function getOrderValueAttribute(): float
    {
        return round((float) $this->selling_price * (float) $this->quantity, 2);
    }
}
