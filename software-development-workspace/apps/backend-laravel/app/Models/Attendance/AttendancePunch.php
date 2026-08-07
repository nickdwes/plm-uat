<?php

namespace App\Models\Attendance;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A clock in and its matching clock out.
 *
 * Several per day, because a real day has a site visit in the middle of it. The
 * day's own first-in and last-out are rolled up from these rather than typed,
 * so the two cannot disagree.
 */
class AttendancePunch extends Model
{
    use BelongsToOrganization;

    protected $table = 'attendance_punches';

    protected $fillable = [
        'organization_id', 'attendance_day_id',
        'clocked_in_at', 'clocked_out_at', 'location_type',
        'is_manual', 'note', 'created_by',
    ];

    protected $casts = [
        'clocked_in_at'  => 'datetime',
        'clocked_out_at' => 'datetime',
        'is_manual'      => 'boolean',
    ];

    public function day(): BelongsTo
    {
        return $this->belongsTo(AttendanceDay::class, 'attendance_day_id');
    }

    /** Null while it is still running, which is what makes it the open one. */
    public function getMinutesAttribute(): ?int
    {
        if ($this->clocked_out_at === null) {
            return null;
        }

        return (int) $this->clocked_in_at->diffInMinutes($this->clocked_out_at);
    }
}
