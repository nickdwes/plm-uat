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

    /**
     * Doing it, helping with it, or just watching it.
     *
     * `tracking` carries no obligation — it is how somebody says "tell me what
     * happens here" without being on the hook for any of it. It is kept in this
     * table rather than a separate watchers one for the same reason assisting
     * is: two tables would have to be stopped from disagreeing about whether
     * the same person is on the task twice.
     */
    public const CAPACITIES = ['assignee', 'assisting', 'tracking'];

    /** What the reader is to a task, in the vocabulary the UI speaks. */
    public const INVOLVEMENT = ['doing' => 'assignee', 'assisting' => 'assisting', 'tracking' => 'tracking'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
