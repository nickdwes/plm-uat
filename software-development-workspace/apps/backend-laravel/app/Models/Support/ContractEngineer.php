<?php

namespace App\Models\Support;

use App\Models\Account\AccountContract;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An engineer named on a customer's contract.
 *
 * The whole point of the routing rewrite. Somebody who installed a customer's
 * network is not interchangeable with somebody who has never seen it, and a
 * queue that hands work out by availability pretends otherwise.
 *
 * A category may be set, so one contract can name a network engineer and a
 * cloud engineer separately — which is how routing picks the right one rather
 * than the only one.
 */
class ContractEngineer extends Model
{
    use BelongsToOrganization;

    protected $table = 'account_contract_engineers';

    public const ROLE_PRIMARY   = 'primary';
    public const ROLE_SECONDARY = 'secondary';

    protected $fillable = [
        'organization_id', 'account_contract_id', 'user_id',
        'role', 'service_category_id', 'notes', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    protected $attributes = ['role' => self::ROLE_PRIMARY, 'is_active' => true];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(AccountContract::class, 'account_contract_id');
    }

    public function engineer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }
}
