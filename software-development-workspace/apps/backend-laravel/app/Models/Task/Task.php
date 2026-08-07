<?php

namespace App\Models\Task;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A task, or a sub-task — they are the same thing at different depths.
 *
 * Not a project feature. Of the legacy's 9,055 tasks only a quarter carried a
 * project; the rest were support, training, meetings and plain internal work,
 * and its twelve categories doubled as the module map. So the project link is
 * one of several and all of them are optional.
 *
 * Sub-tasks are tasks. The legacy's separate sub-phase table meant a sub-phase
 * carried a name and nothing else — no owner, date or status — and one table
 * with a parent gives it everything a task has.
 */
class Task extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'tasks';

    protected $fillable = [
        'organization_id', 'parent_task_id', 'reference',
        'title', 'description', 'status', 'priority', 'deadline',
        'completed_at', 'position', 'created_by',

        // What kind of work, and what raised it. All optional — most tasks
        // belong to nothing but the person doing them.
        'category', 'project_id', 'opportunity_id', 'lead_id', 'research_id', 'account_id',

        'effort_estimate_days', 'started_on', 'assigned_to',
        'requires_approval', 'approved_at', 'approved_by',
    ];

    protected $casts = [
        'deadline'     => 'date',
        'started_on'   => 'date',
        'completed_at' => 'datetime',
        'approved_at'  => 'datetime',
        'position'     => 'integer',
        'requires_approval'    => 'boolean',
        'effort_estimate_days' => 'decimal:2',
    ];

    protected $attributes = ['status' => 'todo', 'priority' => 'medium'];

    /** As the design's task list labels them. */
    public const STATUSES = ['todo', 'in_progress', 'awaiting_approval', 'completed', 'blocked'];

    public const PRIORITIES = ['low', 'medium', 'high'];

    /**
     * The legacy's twelve, which were the module map as much as a filter.
     *
     * Kept whole rather than trimmed to what is built: a task raised against a
     * ticket is a real thing people do, and dropping the category would just
     * move it into "internal" where nothing can find it.
     */
    public const CATEGORIES = [
        'opportunity', 'project', 'support', 'meeting', 'training', 'certificate',
        'research', 'collaboration', 'internal', 'ticket', 'account', 'leave',
    ];

    /** The one status that means the work is done, named once. */
    public const DONE = 'completed';

    public function project(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Project\Project::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Employee::class, 'assigned_to');
    }

    /** Requests to assist, delegate or collaborate. */
    public function requests(): HasMany
    {
        return $this->hasMany(TaskRequest::class);
    }

    /** Documents this task cannot close without. */
    public function requiredDocuments(): HasMany
    {
        return $this->hasMany(TaskRequiredDocument::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_task_id');
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(self::class, 'parent_task_id')->orderBy('position');
    }

    public function members(): HasMany
    {
        return $this->hasMany(TaskMember::class);
    }

    public function dependencies(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class, 'task_dependencies', 'task_id', 'depends_on_task_id',
        )->withTimestamps();
    }

    /** What is waiting on this one — the other direction of the same edge. */
    public function dependents(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class, 'task_dependencies', 'depends_on_task_id', 'task_id',
        )->withTimestamps();
    }

    public function comments(): HasMany
    {
        return $this->hasMany(\App\Models\Project\ProjectComment::class, 'task_id')
            ->whereNull('parent_id')->oldest('id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(\App\Models\Project\ProjectAttachment::class, 'task_id');
    }

    // ── Derived ───────────────────────────────────────────────────────────

    public function getIsDoneAttribute(): bool
    {
        return $this->status === self::DONE;
    }

    /**
     * Whether anything it waits on is still unfinished.
     *
     * Surfaced rather than enforced. Refusing to let somebody start a task
     * because a dependency is open is the kind of rule that gets worked around
     * by deleting the dependency, which loses the information entirely.
     */
    public function getIsBlockedAttribute(): bool
    {
        return $this->relationLoaded('dependencies')
            ? $this->dependencies->contains(fn (self $t) => ! $t->is_done)
            : $this->dependencies()->where('status', '!=', self::DONE)->exists();
    }

    public function getIsOverdueAttribute(): bool
    {
        return ! $this->is_done
            && $this->deadline !== null
            && $this->deadline->isPast();
    }

    // ── Scopes ────────────────────────────────────────────────────────────

    /** Top-level only — sub-tasks belong under their parent, not beside it. */
    public function scopeRoots(Builder $q): Builder
    {
        return $q->whereNull('parent_task_id');
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('status', '!=', self::DONE);
    }

    /**
     * How long it actually took, in days.
     *
     * Against `effort_estimate_days`, this is the comparison the legacy had the
     * data for on nine tasks in ten and never once made.
     */
    public function getElapsedDaysAttribute(): ?int
    {
        if ($this->started_on === null || $this->completed_at === null) {
            return null;
        }

        return (int) $this->started_on->diffInDays($this->completed_at->startOfDay());
    }

    /** Days over or under the estimate. Null until both exist. */
    public function getEffortVarianceAttribute(): ?float
    {
        return $this->effort_estimate_days === null || $this->elapsed_days === null
            ? null
            : round($this->elapsed_days - (float) $this->effort_estimate_days, 2);
    }
}
