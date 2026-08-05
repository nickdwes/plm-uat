<?php

namespace App\Models\Account;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Somebody who works an account.
 *
 * Distinct from the account manager, who owns the relationship. A customer is
 * usually served by several people, and "who deals with this client" has no
 * answer from an owner field alone.
 */
class AccountTeamMember extends Model
{
    use BelongsToOrganization;

    protected $table = 'account_team';

    protected $fillable = ['organization_id', 'account_id', 'employee_id', 'role', 'added_by'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
