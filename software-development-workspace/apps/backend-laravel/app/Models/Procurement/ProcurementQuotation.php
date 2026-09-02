<?php

namespace App\Models\Procurement;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\RecordsAuditTrail;
use App\Models\Training\VendorPartner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one supplier offered. Price and lead time are compared across the
 * quotations automatically; technical and vendor judgement are recorded by the
 * buyer, because neither is in the numbers.
 */
class ProcurementQuotation extends Model
{
    use BelongsToOrganization, RecordsAuditTrail;

    protected $table = 'procurement_quotations';

    protected array $auditExclude = ['updated_at'];

    /** How the total score is weighted, in percent. */
    public const WEIGHTS = ['price' => 40, 'delivery' => 20, 'technical' => 25, 'vendor' => 15];

    protected $fillable = [
        'organization_id', 'rfq_id', 'vendor_partner_id',
        'amount', 'currency', 'lead_time_days',
        'warranty_terms', 'payment_terms', 'delivery_terms', 'valid_until',
        'price_score', 'delivery_score', 'technical_score', 'vendor_score', 'total_score',
        'is_awarded', 'award_justification', 'notes', 'created_by',
    ];

    protected $casts = [
        'amount'          => 'decimal:2',
        'lead_time_days'  => 'integer',
        'valid_until'     => 'date',
        'price_score'     => 'decimal:2',
        'delivery_score'  => 'decimal:2',
        'technical_score' => 'decimal:2',
        'vendor_score'    => 'decimal:2',
        'total_score'     => 'decimal:2',
        'is_awarded'      => 'boolean',
    ];

    protected $attributes = ['currency' => 'KES', 'is_awarded' => false];

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(VendorPartner::class, 'vendor_partner_id');
    }
}
