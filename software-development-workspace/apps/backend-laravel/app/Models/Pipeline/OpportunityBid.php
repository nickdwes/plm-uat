<?php

namespace App\Models\Pipeline;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A tender we have bid on.
 *
 * `outcome_notes` is the legacy's three post-mortem fields collapsed into one:
 * it held a winning-bid note, an action-if-lost note and a root-cause
 * document, which in practice were three places to write the same paragraph
 * and two of them were always empty.
 */
class OpportunityBid extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'opportunity_bids';

    protected $fillable = [
        'organization_id', 'opportunity_id', 'tender_number', 'bid_number', 'tender_name',
        'tender_validity_date', 'bid_bond_validity_date', 'bid_bond_value',
        'bidding_cost', 'bidding_value', 'tender_close_date', 'expected_award_date',
        'status', 'scope_agreed', 'outcome_notes', 'created_by',
    ];

    protected $casts = [
        'tender_validity_date'   => 'date',
        'bid_bond_validity_date' => 'date',
        'tender_close_date'      => 'date',
        'expected_award_date'    => 'date',
        'bid_bond_value'         => 'decimal:2',
        'bidding_cost'           => 'decimal:2',
        'bidding_value'          => 'decimal:2',
        'scope_agreed'           => 'boolean',
    ];

    protected $attributes = ['status' => 'bidding', 'scope_agreed' => false];

    public const STATUSES = ['bidding', 'won', 'lost', 'withdrawn'];

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(OpportunityDocument::class, 'bid_id');
    }

    /**
     * What we stand to make on it, if both figures are known.
     *
     * Derived here because unlike an estimate's profit this one is not
     * negotiated — it is simply what we bid less what it costs us.
     */
    public function getBiddingProfitAttribute(): ?string
    {
        if ($this->bidding_value === null || $this->bidding_cost === null) {
            return null;
        }

        return bcsub((string) $this->bidding_value, (string) $this->bidding_cost, 2);
    }

    /** A bond that lapses before the award is a bid that cannot be honoured. */
    public function getBondLapsesBeforeAwardAttribute(): bool
    {
        return $this->bid_bond_validity_date !== null
            && $this->expected_award_date !== null
            && $this->bid_bond_validity_date->lt($this->expected_award_date);
    }
}
