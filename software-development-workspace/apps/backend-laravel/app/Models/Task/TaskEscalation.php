<?php

namespace App\Models\Task;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A rung climbed: who was told about a late task, and why. */
class TaskEscalation extends Model
{
    use BelongsToOrganization;

    protected $table = 'task_escalations';

    /** What set it off. */
    public const TRIGGER_OVERDUE       = 'overdue';
    public const TRIGGER_PAUSE_OVERRUN = 'pause_overrun';
    public const TRIGGER_REPEAT_SLIPS  = 'repeat_extensions';

    public const TRIGGERS = [
        self::TRIGGER_OVERDUE       => 'Past its deadline',
        self::TRIGGER_PAUSE_OVERRUN => 'Paused longer than promised',
        self::TRIGGER_REPEAT_SLIPS  => 'Deadline moved repeatedly',
    ];

    /**
     * How far up it goes.
     *
     * Four rungs rather than the spec's five: a Service Delivery Manager is not
     * a role this system holds anybody in, and inventing a lookup that always
     * resolves to nobody would be a rung that silently never fires.
     */
    public const LEVEL_OWNER      = 'owner';
    public const LEVEL_PM         = 'project_manager';
    public const LEVEL_DEPARTMENT = 'department_head';
    public const LEVEL_SPONSOR    = 'sponsor';

    public const LEVELS = [
        self::LEVEL_OWNER      => 'Task owner',
        self::LEVEL_PM         => 'Project manager',
        self::LEVEL_DEPARTMENT => 'Department head',
        self::LEVEL_SPONSOR    => 'Executive sponsor',
    ];

    protected $fillable = [
        'organization_id', 'task_id', 'trigger', 'level',
        'to_employee_id', 'detail', 'escalated_at',
    ];

    protected $casts = ['escalated_at' => 'datetime'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'to_employee_id');
    }
}
