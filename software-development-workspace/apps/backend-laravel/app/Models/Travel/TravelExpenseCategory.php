<?php

namespace App\Models\Travel;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class TravelExpenseCategory extends Model
{
    use BelongsToOrganization;

    /** What closing a line of this category requires. */
    public const EVIDENCE_TYPES = ['none', 'acknowledgement', 'receipt'];

    /** Whose job it is to supply it. */
    public const EVIDENCE_PROVIDERS = ['requestor', 'finance', 'either'];

    protected $fillable = [
        'organization_id', 'name', 'code', 'requires_receipt',
        'evidence_type', 'evidence_provider', 'is_active',
    ];

    protected $casts = ['requires_receipt' => 'boolean', 'is_active' => 'boolean'];

    protected $attributes = [
        'requires_receipt'  => false,
        'evidence_type'     => 'none',
        'evidence_provider' => 'requestor',
        'is_active'         => true,
    ];

    /**
     * Whether this person may supply evidence for this category.
     *
     * Getting this wrong is what makes reconciliation stall: ask a traveller
     * for an airline invoice they never saw and the request stays open until
     * somebody closes it out of impatience.
     */
    public function acceptsEvidenceFrom(bool $isTraveller, bool $isFinance): bool
    {
        return match ($this->evidence_provider) {
            'requestor' => $isTraveller,
            'finance'   => $isFinance,
            default     => $isTraveller || $isFinance,
        };
    }
}
