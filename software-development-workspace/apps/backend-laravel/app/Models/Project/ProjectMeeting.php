<?php

namespace App\Models\Project;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Teams meeting scheduled against a project — the correspondence tab's calls.
 */
class ProjectMeeting extends Model
{
    use BelongsToOrganization;

    protected $table = 'project_meetings';

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'organization_id', 'task_id', 'project_id',
        'subject', 'scheduled_at', 'duration_minutes', 'attendees',
        'graph_event_id', 'join_url', 'recording_url', 'transcript',
        'status', 'cancelled_at', 'created_by',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'attendees'    => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Task\Task::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
