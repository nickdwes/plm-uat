<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveBalance extends Model
{
    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'year',
        'entitled_days',
        'used_days',
        'pending_days',
        'carried_days',
    ];

    protected $casts = [
        'entitled_days' => 'decimal:1',
        'used_days'     => 'decimal:1',
        'pending_days'  => 'decimal:1',
        'carried_days'  => 'decimal:1',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function getAvailableDaysAttribute(): float
    {
        return (float) $this->entitled_days
            + (float) $this->carried_days
            - (float) $this->used_days
            - (float) $this->pending_days;
    }
}
