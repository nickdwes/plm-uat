<?php

namespace App\Models\Procurement;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * Which approval stages an organisation applies to a purchase, and above what
 * amount. Configuration rather than code — adding a fourth level is a row.
 */
class ProcurementApprovalStage extends Model
{
    use BelongsToOrganization;

    public const STAGES = ['department_head', 'finance', 'operations', 'executive'];

    protected $fillable = [
        'organization_id', 'stage', 'category', 'sequence',
        'required_above_amount', 'currency', 'is_active',
    ];

    protected $casts = [
        'sequence'              => 'integer',
        'required_above_amount' => 'decimal:2',
        'is_active'             => 'boolean',
    ];

    protected $attributes = ['is_active' => true, 'currency' => 'KES'];

    /**
     * Whether this stage applies to a purchase of the given value.
     *
     * A null threshold means always. Currency must match, because comparing
     * across currencies would depend on a rate nobody recorded.
     */
    public function appliesTo(float $amount, string $currency): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->required_above_amount === null) {
            return true;
        }

        return $currency === $this->currency && $amount > (float) $this->required_above_amount;
    }
}
