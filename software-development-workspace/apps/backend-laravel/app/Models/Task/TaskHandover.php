<?php

namespace App\Models\Task;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Employee;
use App\Models\LeaveRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Somebody covering somebody else's task while they are away.
 *
 * A record rather than a reassignment. Overwriting the task's `assigned_to`
 * would lose who it goes back to, and after two consecutive leaves nobody could
 * say whose task it originally was — which is how work quietly changes hands
 * for good on the back of a fortnight in Mombasa.
 */
class TaskHandover extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'task_id', 'leave_request_id',
        'from_employee_id', 'to_employee_id', 'note',
        'starts_on', 'ends_on', 'released_at', 'created_by',
    ];

    protected $casts = [
        'starts_on'   => 'date',
        'ends_on'     => 'date',
        'released_at' => 'datetime',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    public function coverer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'to_employee_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'from_employee_id');
    }

    public function scopeStanding(Builder $q): Builder
    {
        return $q->whereNull('released_at');
    }

    /** Still standing, and within its dates. */
    public function getIsActiveAttribute(): bool
    {
        return $this->released_at === null
            && ! $this->starts_on->isFuture()
            && ! $this->ends_on->isPast();
    }
}
