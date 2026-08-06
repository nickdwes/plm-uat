<?php

namespace App\Models\Pipeline;

use App\Models\Account\AccountContact;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Somebody on the customer's side who decides, or blocks.
 *
 * Named against the lead rather than pulled from the customer's contact list,
 * because who matters is a fact about this deal — the head of IT may be the
 * decision maker on one and irrelevant to the next.
 */
class LeadStakeholder extends Model
{
    use BelongsToOrganization;

    protected $table = 'lead_stakeholders';

    protected $fillable = [
        'organization_id', 'lead_id', 'account_contact_id',
        'name', 'role', 'email', 'phone', 'influence',
    ];

    /** Whether they can say yes, as distinct from whether they matter. */
    public const INFLUENCES = ['decision_maker', 'influencer', 'blocker', 'user'];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(AccountContact::class, 'account_contact_id');
    }
}
