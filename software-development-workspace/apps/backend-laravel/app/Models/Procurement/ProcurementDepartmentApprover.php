<?php

namespace App\Models\Procurement;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Somebody allowed to act on a department's procurement approvals besides its
 * lead — cover, delegation, a shared services desk. Time-boxed, so an
 * arrangement made for one absence does not outlive it.
 */
class ProcurementDepartmentApprover extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'department_id', 'employee_id',
        'effective_from', 'effective_to', 'is_active',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to'   => 'date',
        'is_active'      => 'boolean',
    ];

    protected $attributes = ['is_active' => true];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Arrangements in force on a date — expired cover is not delegation. */
    public function scopeInForce(Builder $q, ?string $on = null): Builder
    {
        $on ??= now()->toDateString();

        return $q->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('effective_from')->orWhereDate('effective_from', '<=', $on))
            ->where(fn ($w) => $w->whereNull('effective_to')->orWhereDate('effective_to', '>=', $on));
    }
}
