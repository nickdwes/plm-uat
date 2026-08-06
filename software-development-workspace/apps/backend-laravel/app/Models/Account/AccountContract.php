<?php

namespace App\Models\Account;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What we have agreed to do for a customer, and until when.
 *
 * Ported from Plmv2. The question it answers is renewals: a support contract
 * that lapses unnoticed is revenue lost quietly, which is why `end_date` is
 * mandatory and `expiringWithin` exists.
 */
class AccountContract extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'account_id', 'reference', 'name', 'contract_number', 'type',
        'contract_value', 'billing_cycle', 'owner', 'project_reference',
        'start_date', 'end_date', 'status', 'completed_at', 'notes', 'created_by',
    ];

    protected $casts = [
        'start_date'     => 'date',
        'end_date'       => 'date',
        'completed_at'   => 'datetime',
        'contract_value' => 'decimal:2',
    ];

    protected $attributes = ['status' => 'active'];

    public const STATUSES = ['draft', 'in_progress', 'active', 'completed', 'expired', 'terminated'];

    /** What kind of agreement it is, as the contract list colours them. */
    public const TYPES = ['subscription', 'sla', 'service', 'licence', 'framework', 'support'];

    public const BILLING_CYCLES = ['one_off', 'monthly', 'quarterly', 'biannual', 'annual'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Whether it has run out, derived rather than stored.
     *
     * A stored "expired" flag is only correct until the next midnight, and
     * nothing here runs at midnight to fix it.
     */
    public function getIsExpiredAttribute(): bool
    {
        return ! in_array($this->status, ['terminated', 'completed'], true)
            && $this->end_date?->isPast() === true;
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(AccountAttachment::class, 'account_contract_id');
    }

    public function scopeExpiringWithin(Builder $q, int $days): Builder
    {
        return $q->where('status', 'active')
            ->whereBetween('end_date', [now()->toDateString(), now()->addDays($days)->toDateString()]);
    }
}
