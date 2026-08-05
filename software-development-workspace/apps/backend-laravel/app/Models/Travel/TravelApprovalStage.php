<?php

namespace App\Models\Travel;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * Which approval stages an organisation applies, and above what amount.
 *
 * Configuration rather than code: the chain has already changed once between
 * the legacy system and this one, and will change again.
 */
class TravelApprovalStage extends Model
{
    use BelongsToOrganization;

    public const STAGES = ['department_head', 'function_head', 'finance', 'executive'];

    protected $fillable = [
        'organization_id', 'stage', 'sequence', 'required_above_amount', 'currency', 'is_active',
    ];

    protected $casts = [
        'sequence'              => 'integer',
        'required_above_amount' => 'decimal:2',
        'is_active'             => 'boolean',
    ];

    protected $attributes = ['is_active' => true, 'currency' => 'KES'];

    /**
     * Whether this stage applies to a request of the given value.
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
