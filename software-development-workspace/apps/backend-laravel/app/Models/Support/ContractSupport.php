<?php

namespace App\Models\Support;

use App\Models\Account\AccountContract;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The support arrangements on a contract.
 *
 * Held apart from the contract itself: a contract is a commercial record owned
 * by the accounts side, and who supports it changes far more often than its
 * terms do.
 */
class ContractSupport extends Model
{
    use BelongsToOrganization;

    protected $table = 'account_contract_support';

    protected $fillable = [
        'organization_id', 'account_contract_id',
        'primary_team_id', 'escalation_team_id', 'service_desk_user_id',
        'vendor_contact_name', 'vendor_contact_email', 'notes',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(AccountContract::class, 'account_contract_id');
    }

    public function primaryTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'primary_team_id');
    }

    public function escalationTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'escalation_team_id');
    }

    public function analyst(): BelongsTo
    {
        return $this->belongsTo(User::class, 'service_desk_user_id');
    }
}
