<?php

namespace App\Models\Roster;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HandoverReport extends Model
{
    protected $table = 'roster_handover_reports';

    protected $fillable = [
        'organization_id', 'schedule_id', 'shift_type_id',
        'outgoing_employee_id', 'incoming_employee_id',
        'report_date', 'shift_start', 'shift_end',
        'tasks_completed', 'ongoing_issues', 'tasks_pending',
        'equipment_notes', 'general_notes',
        'acknowledged_at', 'acknowledged_by_id',
    ];

    protected $casts = [
        'report_date'     => 'date',
        'acknowledged_at' => 'datetime',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(RosterSchedule::class);
    }

    public function shiftType(): BelongsTo
    {
        return $this->belongsTo(RosterShiftType::class);
    }

    public function outgoingEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'outgoing_employee_id');
    }

    public function incomingEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'incoming_employee_id');
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'acknowledged_by_id');
    }
}
