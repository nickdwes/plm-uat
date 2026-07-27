<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkLocationCheckIn extends Model
{
    protected $table = 'work_location_checkins';

    protected $fillable = [
        'organization_id',
        'employee_id',
        'location_type',
        'customer_name',
        'notes',
        'checked_in_at',
        'checked_out_at',
    ];

    protected $casts = [
        'checked_in_at'  => 'datetime',
        'checked_out_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function getDurationMinutesAttribute(): ?int
    {
        if (!$this->checked_in_at) {
            return null;
        }

        return (int) $this->checked_in_at->diffInMinutes($this->checked_out_at ?? now());
    }
}
