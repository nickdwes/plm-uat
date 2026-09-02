<?php

namespace App\Models\Project;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Employee;
use App\Models\Task\Task;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Something the project waits on: another task, an external party, a vendor
 * delivery. When it points at a task it can borrow that task's live status;
 * otherwise a human sets pending → in_progress → resolved by hand.
 */
class ProjectDependency extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'project_dependencies';

    public const KIND_TASK     = 'task';
    public const KIND_EXTERNAL = 'external';
    public const KIND_VENDOR   = 'vendor';
    public const KIND_OTHER    = 'other';
    public const KINDS = [self::KIND_TASK, self::KIND_EXTERNAL, self::KIND_VENDOR, self::KIND_OTHER];

    public const STATUS_PENDING     = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_RESOLVED    = 'resolved';
    public const STATUSES = [self::STATUS_PENDING, self::STATUS_IN_PROGRESS, self::STATUS_RESOLVED];

    protected $fillable = [
        'organization_id', 'project_id', 'task_id',
        'title', 'description', 'kind', 'depends_on_task_id',
        'status', 'owner_employee_id', 'due_on',
    ];

    protected $casts = [
        'due_on' => 'date',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function dependsOnTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'depends_on_task_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'owner_employee_id');
    }
}
