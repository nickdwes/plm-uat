<?php

namespace App\Models\Roster;

use App\Models\Employee;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RosterShiftSwapRequest extends Model
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_ACCEPTED  = 'accepted';
    public const STATUS_REJECTED  = 'rejected';
    public const STATUS_APPROVED  = 'approved';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'organization_id',
        'requester_assignment_id',
        'requested_assignment_id',
        'requester_id',
        'requested_employee_id',
        'status',
        'requester_notes',
        'responder_notes',
        'manager_notes',
        'approved_by',
        'responded_at',
        'approved_at',
    ];

    protected $casts = [
        'responded_at' => 'datetime',
        'approved_at'  => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function requesterAssignment(): BelongsTo
    {
        return $this->belongsTo(RosterAssignment::class, 'requester_assignment_id');
    }

    public function requestedAssignment(): BelongsTo
    {
        return $this->belongsTo(RosterAssignment::class, 'requested_assignment_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requester_id');
    }

    public function requestedEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requested_employee_id');
    }

    public function approvedByEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approved_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isAccepted(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }
}
