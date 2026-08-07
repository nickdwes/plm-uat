<?php

namespace App\Models\Attendance;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Employee;
use App\Models\Task\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something somebody said they would finish today, and how it went.
 *
 * Not a slot on a clock. The question this module answers is what was promised,
 * whether it was delivered, and — when it was not — whether anybody was told.
 * An hour grid answers none of those; it answers where the time went, which is
 * a timesheet, and timesheets get filled in on Friday from memory.
 *
 * Points at a task where there is one, because planning must not create a
 * second copy of work the Tasks module cannot see. Optional, because "call the
 * client back" is a real commitment that nobody wants as a task record.
 */
class AttendanceCommitment extends Model
{
    use BelongsToOrganization;

    protected $table = 'attendance_commitments';

    protected $fillable = [
        'organization_id', 'attendance_day_id', 'task_id',
        'title', 'kind', 'position', 'starts_at', 'ends_at', 'note',
        'outcome', 'reason', 'reported_at', 'reported_by', 'carried_to_id',
        'created_by',
    ];

    protected $casts = ['reported_at' => 'datetime'];

    protected $attributes = ['kind' => 'work', 'position' => 0];

    public const KINDS = ['work', 'meeting', 'break', 'admin', 'enablement'];

    /** Done, or not. Null is the absence of an answer, not a third one. */
    public const OUTCOMES = ['done', 'not_done'];

    public function day(): BelongsTo
    {
        return $this->belongsTo(AttendanceDay::class, 'attendance_day_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reported_by');
    }

    /** Where it went instead, when it was moved to another day. */
    public function carriedTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'carried_to_id');
    }

    /**
     * Slipped, and nobody was told.
     *
     * The number worth watching. Not "how many tasks slipped" — work slips, and
     * a manager reacting to that is reacting to noise. Work that slipped in
     * silence is the thing that was previously uncountable.
     */
    public function scopeUnexplained(Builder $q): Builder
    {
        return $q->whereNull('outcome')->orWhere(function (Builder $w) {
            $w->where('outcome', 'not_done')->whereNull('reason');
        });
    }

    public function getIsDoneAttribute(): bool
    {
        return $this->outcome === 'done';
    }

    /** Answered one way or the other, with a reason where one is owed. */
    public function getIsExplainedAttribute(): bool
    {
        if ($this->outcome === 'done') {
            return true;
        }

        return $this->outcome === 'not_done' && filled($this->reason);
    }

    /** Optional, and only for people who want it. Nothing is built on it. */
    public function getMinutesAttribute(): ?int
    {
        if ($this->starts_at === null || $this->ends_at === null) {
            return null;
        }

        return max(0, self::minuteOf($this->ends_at) - self::minuteOf($this->starts_at));
    }

    private static function minuteOf(mixed $time): int
    {
        [$h, $m] = array_map('intval', explode(':', substr((string) $time, 0, 5)));

        return $h * 60 + $m;
    }
}
