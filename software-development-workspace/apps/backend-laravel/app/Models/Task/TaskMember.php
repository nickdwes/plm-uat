<?php

namespace App\Models\Task;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Somebody on a task, and in what capacity.
 *
 * The design shows Assignees and Assisting separately. One table with a
 * capacity rather than two, because two would have to be kept from disagreeing
 * about whether the same person is on the task twice.
 */
class TaskMember extends Model
{
    use BelongsToOrganization;

    protected $table = 'task_members';

    protected $fillable = ['organization_id', 'task_id', 'employee_id', 'capacity'];

    /** Doing it, versus helping with it. */
    public const CAPACITIES = ['assignee', 'assisting'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
