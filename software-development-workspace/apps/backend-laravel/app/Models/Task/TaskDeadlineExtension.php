<?php

namespace App\Models\Task;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A deadline that moved, and why.
 *
 * `tasks.deadline` is one column, so moving it used to erase the date it
 * replaced. A task that slipped four times then read exactly like one estimated
 * correctly first time — which is the difference a delivery review exists to
 * find.
 */
class TaskDeadlineExtension extends Model
{
    use BelongsToOrganization;

    protected $table = 'task_deadline_extensions';

    protected $fillable = [
        'organization_id', 'task_id', 'previous_deadline', 'new_deadline',
        'reason', 'impact', 'dependency', 'responsible_party',
        'task_issue_id', 'requested_by',
    ];

    protected $casts = [
        'previous_deadline' => 'date',
        'new_deadline'      => 'date',
    ];

    /** How far it moved. Null where there was nothing to move from. */
    public function daysMoved(): ?int
    {
        return $this->previous_deadline
            ? (int) $this->previous_deadline->diffInDays($this->new_deadline, false)
            : null;
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(TaskIssue::class, 'task_issue_id');
    }
}
