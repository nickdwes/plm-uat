<?php

namespace App\Models\Task;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Something in the way of a task.
 *
 * Separate from the task's status because they answer different questions: a
 * task can be in progress and still carry two unresolved issues. The legacy
 * raised 242 and left 135 unresolved, and 164 of the 242 were High — an issue
 * gets raised when something is actually blocking, not as a note.
 */
class TaskIssue extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'task_id', 'name', 'details',
        'priority', 'status', 'resolved_at', 'resolved_by', 'raised_by',
    ];

    protected $casts = ['resolved_at' => 'datetime'];

    protected $appends = ['is_open'];

    public const PRIORITIES = ['low', 'medium', 'high'];

    /** Still in the way, or not. The only two the legacy ever used. */
    public const STATUSES = ['open', 'resolved'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function escalations(): HasMany
    {
        return $this->hasMany(TaskIssueEscalation::class)->latest('id');
    }

    public function raiser(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'raised_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'resolved_by');
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('status', 'open');
    }

    public function getIsOpenAttribute(): bool
    {
        return $this->status === 'open';
    }
}
