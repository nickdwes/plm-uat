<?php

namespace App\Models\Roster;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Organization;

class RosterShiftType extends Model
{
    protected $fillable = [
        'organization_id',
        'shift_group_id',
        'name',
        'start_time',
        'end_time',
        'break_start',
        'break_duration_minutes',
        'duration_minutes',
        'is_overnight',
        'is_active',
    ];

    protected $casts = [
        'break_duration_minutes' => 'integer',
        'duration_minutes'       => 'integer',
        'is_overnight'           => 'boolean',
        'is_active'              => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function shiftGroup(): BelongsTo
    {
        return $this->belongsTo(RosterShiftGroup::class, 'shift_group_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(RosterAssignment::class, 'shift_type_id');
    }

    /**
     * Net working minutes after deducting any scheduled break.
     */
    public function getNetMinutesAttribute(): int
    {
        return $this->duration_minutes - $this->break_duration_minutes;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
