<?php

namespace App\Models\Support;

use App\Models\Account\AccountContract;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What was promised, to whom.
 *
 * Attached to a contract, which is where the old system kept it and the one
 * thing its SLA design got right. A null contract is the organisation's
 * fallback, so a ticket from an account with no contract still has a target
 * rather than no answer at all.
 */
class Sla extends Model
{
    use BelongsToOrganization;

    protected $table = 'support_slas';

    protected $fillable = [
        'organization_id', 'name', 'account_contract_id',
        'support_calendar_id', 'is_default', 'is_active',
    ];

    protected $casts = ['is_default' => 'boolean', 'is_active' => 'boolean'];

    protected $attributes = ['is_default' => false, 'is_active' => true];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(AccountContract::class, 'account_contract_id');
    }

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(Calendar::class, 'support_calendar_id');
    }

    public function targets(): HasMany
    {
        return $this->hasMany(SlaTarget::class, 'support_sla_id');
    }

    public function targetFor(?int $priorityId): ?SlaTarget
    {
        if ($priorityId === null) {
            return null;
        }

        return $this->targets()->where('priority_id', $priorityId)->first();
    }
}
