<?php

namespace App\Models\Attendance;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Task\Task;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A span of a day, and what is meant to happen in it.
 *
 * Points at a task rather than describing one. The Add Task modal offers
 * Project and Task as dropdowns, which settles it: you are giving existing work
 * a slot, not writing a second copy of it where the Tasks module cannot see.
 *
 * The link is optional because Break and Lunch are real parts of a day, and a
 * plan that is eight solid hours of deliverables is not a plan anybody keeps.
 */
class AttendanceBlock extends Model
{
    use BelongsToOrganization;

    protected $table = 'attendance_blocks';

    protected $fillable = [
        'organization_id', 'attendance_day_id', 'task_id',
        'title', 'kind', 'starts_at', 'ends_at', 'note', 'created_by',
    ];

    protected $attributes = ['kind' => 'work'];

    /**
     * What a span is for.
     *
     * `enablement` is here rather than waiting for the enablement-day work,
     * because somebody planning a training block needs to be able to say so
     * now, and a kind added later cannot describe blocks already written.
     */
    public const KINDS = ['work', 'meeting', 'break', 'admin', 'enablement'];

    public function day(): BelongsTo
    {
        return $this->belongsTo(AttendanceDay::class, 'attendance_day_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** Times are stored as times, so the arithmetic stays on the clock. */
    public function getMinutesAttribute(): int
    {
        return max(0, $this->endMinute() - $this->startMinute());
    }

    /** Whether this span and another cover any of the same clock. */
    public function overlaps(self $other): bool
    {
        return $this->startMinute() < $other->endMinute()
            && $other->startMinute() < $this->endMinute();
    }

    public function startMinute(): int
    {
        return self::minuteOf($this->starts_at);
    }

    public function endMinute(): int
    {
        return self::minuteOf($this->ends_at);
    }

    private static function minuteOf(mixed $time): int
    {
        [$h, $m] = array_map('intval', explode(':', substr((string) $time, 0, 5)));

        return $h * 60 + $m;
    }
}
