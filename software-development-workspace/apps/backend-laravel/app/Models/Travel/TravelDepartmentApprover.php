<?php

namespace App\Models\Travel;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone named to approve travel for a department or function.
 *
 * Exists because `departments.lead_employee_id` names one person, and one
 * person on leave stops every request behind them.
 */
class TravelDepartmentApprover extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'department_id', 'functional_unit_id', 'employee_id',
        'can_act_on_behalf', 'effective_from', 'effective_to', 'is_active', 'created_by',
    ];

    protected $casts = [
        'can_act_on_behalf' => 'boolean',
        'is_active'         => 'boolean',
        'effective_from'    => 'date',
        'effective_to'      => 'date',
    ];

    protected $attributes = ['can_act_on_behalf' => true, 'is_active' => true];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Arrangements in force on a given date.
     *
     * A cover arrangement for a period of leave expires on its own rather than
     * relying on somebody remembering to remove it.
     */
    public function scopeInForce(Builder $q, ?string $on = null): Builder
    {
        $date = $on ?? now()->toDateString();

        return $q->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date))
            ->where(fn ($w) => $w->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date));
    }
}
