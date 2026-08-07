<?php

namespace App\Models\Project;

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
 * The legacy's plan phase and sub-phase were separate tables, which meant a
 * sub-phase carried a name and nothing else: no owner, no date, no status.
 * One table with a parent gives a sub-task everything a task has, and the
 * import from 527 phases and 485 sub-phases lands on it unchanged.
 */
class ProjectTask extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'project_tasks';

    protected $fillable = [
        'organization_id', 'project_id', 'parent_task_id', 'reference',
        'title', 'description', 'status', 'priority', 'deadline',
        'completed_at', 'position', 'created_by',
    ];

    protected $casts = [
        'deadline'     => 'date',
        'completed_at' => 'datetime',
        'position'     => 'integer',
    ];

    protected $attributes = ['status' => 'todo', 'priority' => 'medium'];

    /** As the design's task list labels them. */
    public const STATUSES = ['todo', 'in_progress', 'awaiting_approval', 'completed', 'blocked'];

    public const PRIORITIES = ['low', 'medium', 'high'];

    /** The one status that means the work is done, named once. */
    public const DONE = 'completed';

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
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
        return $this->hasMany(ProjectTaskMember::class);
    }

    public function dependencies(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class, 'project_task_dependencies', 'project_task_id', 'depends_on_task_id',
        )->withTimestamps();
    }

    /** What is waiting on this one — the other direction of the same edge. */
    public function dependents(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class, 'project_task_dependencies', 'depends_on_task_id', 'project_task_id',
        )->withTimestamps();
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ProjectComment::class)->whereNull('parent_id')->oldest('id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ProjectAttachment::class);
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
}
