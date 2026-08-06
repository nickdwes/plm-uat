<?php

namespace App\Models\Account;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something that happened with a customer.
 *
 * See the migration for why manual and system entries share one table and only
 * manual ones move `last_contact_at`.
 */
class AccountActivity extends Model
{
    use BelongsToOrganization;

    protected $table = 'account_activities';

    protected $fillable = [
        'organization_id', 'account_id', 'kind', 'type', 'body',
        'occurred_at', 'employee_id', 'created_by',
    ];

    protected $casts = ['occurred_at' => 'datetime'];

    protected $attributes = ['kind' => 'manual', 'type' => 'note'];

    /** What a person can log. System entries are written by the application. */
    public const TYPES = ['note', 'call', 'meeting', 'email', 'visit'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
