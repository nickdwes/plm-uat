<?php

namespace App\Models\Roster;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RosterAssignment extends Model
{
    public const TYPE_SHIFT   = 'shift';
    public const TYPE_DAY_OFF = 'day_off';
    public const TYPE_LEAVE   = 'leave';

    protected $fillable = [
        'schedule_id',
        'employee_id',
        'shift_type_id',
        'assignment_date',
        'type',
        'notes',
    ];

    protected $casts = [
        'assignment_date' => 'date',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(RosterSchedule::class, 'schedule_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function shiftType(): BelongsTo
    {
        return $this->belongsTo(RosterShiftType::class, 'shift_type_id');
    }

    public function swapRequestsAsRequester(): HasMany
    {
        return $this->hasMany(RosterShiftSwapRequest::class, 'requester_assignment_id');
    }

    public function swapRequestsAsTarget(): HasMany
    {
        return $this->hasMany(RosterShiftSwapRequest::class, 'requested_assignment_id');
    }
}
