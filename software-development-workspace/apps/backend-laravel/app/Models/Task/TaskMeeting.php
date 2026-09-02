<?php

namespace App\Models\Task;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Teams meeting scheduled against a task that has no project or ticket to
 * carry it — an internal review, a standalone call. It lives here so the task's
 * Meetings tab always has a home, mirroring project and ticket meetings.
 */
class TaskMeeting extends Model
{
    use BelongsToOrganization;

    protected $table = 'task_meetings';

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'organization_id', 'task_id',
        'subject', 'scheduled_at', 'duration_minutes', 'attendees',
        'graph_event_id', 'join_url', 'recording_url', 'transcript',
        'status', 'cancelled_at', 'created_by',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'attendees'    => 'array',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
