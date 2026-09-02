<?php

namespace App\Models\Project;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Employee;
use App\Models\Task\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An issue raised against a project (optionally a specific task), with its own
 * open → in_progress → resolved lifecycle and an optional escalation.
 */
class ProjectIssue extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'project_issues';

    public const STATUS_OPEN        = 'open';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_RESOLVED    = 'resolved';
    public const STATUSES  = [self::STATUS_OPEN, self::STATUS_IN_PROGRESS, self::STATUS_RESOLVED];
    public const SEVERITIES = ['low', 'medium', 'high', 'critical'];

    protected $fillable = [
        'organization_id', 'project_id', 'task_id',
        'title', 'description', 'status', 'severity',
        'raised_by', 'resolved_at', 'resolved_by',
        'escalated_at', 'escalated_to', 'escalation_reason',
    ];

    protected $casts = [
        'resolved_at'  => 'datetime',
        'escalated_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function raisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    public function escalatedTo(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'escalated_to');
    }

    public function isEscalated(): bool
    {
        return $this->escalated_at !== null;
    }
}
