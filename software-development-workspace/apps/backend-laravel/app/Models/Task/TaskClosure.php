<?php

namespace App\Models\Task;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What actually came of a task.
 *
 * Written once, when the task is finished, and not editable afterwards — a
 * closure summary that can be rewritten later is a summary a review cannot
 * lean on. The four required fields are the ones a project report asks for and
 * a completion date cannot answer: what was done, what was handed over, what
 * got in the way, and what it achieved.
 */
class TaskClosure extends Model
{
    use BelongsToOrganization;

    protected $table = 'task_closures';

    protected $fillable = [
        'organization_id', 'task_id',
        'work_completed', 'deliverables', 'challenges', 'outcomes', 'lessons_learned',
        'closed_by', 'closed_at',
    ];

    protected $casts = ['closed_at' => 'datetime'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
