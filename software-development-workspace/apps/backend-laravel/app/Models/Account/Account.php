<?php

namespace App\Models\Account;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A customer.
 *
 * Exists ahead of Travel because a travel request names the customer the trip
 * is for, and 94 of 102 legacy requests did so — it is not an optional field
 * in practice.
 */
class Account extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $fillable = [
        'organization_id', 'name', 'status',
        'account_manager_id', 'account_type_id', 'account_sector_id', 'account_subsector_id',
        'country', 'physical_location', 'number_of_branches', 'number_of_employees',
        'fund_source', 'vat_registered', 'tax_pin', 'details', 'created_by',
    ];

    protected $casts = [
        'vat_registered'      => 'boolean',
        'number_of_branches'  => 'integer',
        'number_of_employees' => 'integer',
    ];

    protected $attributes = [
        'status'         => 'prospect',
        'vat_registered' => false,
    ];

    public const STATUSES = ['prospect', 'active', 'inactive'];

    // ── Relationships ─────────────────────────────────────────────────────

    public function accountManager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'account_manager_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(AccountType::class, 'account_type_id');
    }

    public function sector(): BelongsTo
    {
        return $this->belongsTo(AccountSector::class, 'account_sector_id');
    }

    public function subsector(): BelongsTo
    {
        return $this->belongsTo(AccountSubsector::class, 'account_subsector_id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(AccountContact::class);
    }

    public function primaryContact(): HasMany
    {
        return $this->contacts()->where('is_primary', true);
    }

    // ── Scopes ────────────────────────────────────────────────────────────

    /**
     * Customers this employee manages.
     *
     * Backs `accounts.view_own`, which is the scope an account manager gets
     * when they should see their own book and not the whole customer base.
     */
    public function scopeManagedBy(Builder $q, ?int $employeeId): Builder
    {
        // A null employee must match nothing rather than everything: a user
        // with no employee record would otherwise see every customer.
        return $q->where('account_manager_id', $employeeId ?? 0);
    }
}
