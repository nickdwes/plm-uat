<?php

namespace App\Models\Account;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'organization_id', 'account_id', 'name', 'contract_number', 'type',
        'contract_value', 'start_date', 'end_date', 'status', 'notes', 'created_by',
    ];

    protected $casts = [
        'start_date'     => 'date',
        'end_date'       => 'date',
        'contract_value' => 'decimal:2',
    ];

    protected $attributes = ['status' => 'active'];

    public const STATUSES = ['draft', 'active', 'expired', 'terminated'];

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
        return $this->status !== 'terminated' && $this->end_date?->isPast() === true;
    }

    public function scopeExpiringWithin(Builder $q, int $days): Builder
    {
        return $q->where('status', 'active')
            ->whereBetween('end_date', [now()->toDateString(), now()->addDays($days)->toDateString()]);
    }
}
