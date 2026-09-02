<?php

namespace App\Models\Project;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Task\Task;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One phase of a project's delivery lifecycle. Sub-phases point at a parent.
 * Status and progress are derived from the tasks under it, never stored.
 */
class ProjectPhase extends Model
{
    use BelongsToOrganization;

    protected $table = 'project_phases';

    /** The nine-stage default lifecycle, seeded on demand. */
    public const DEFAULTS = [
        'Preparation', 'Planning', 'Design', 'Implementation', 'Testing',
        'Operationalization', 'Closure', 'Training', 'Support',
    ];

    protected $fillable = [
        'organization_id', 'project_id', 'parent_id', 'name', 'slug', 'position', 'due_on', 'created_by',
    ];

    protected $casts = ['due_on' => 'date'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'phase_id');
    }

    /** This phase and every sub-phase under it. */
    public function descendantIds(): array
    {
        return [$this->id, ...$this->children()->pluck('id')->all()];
    }

    /**
     * Complete when it has work and all of it — its own and its sub-phases' —
     * is done. An empty phase is not "complete": there is nothing to have
     * finished, so a milestone must not treat it as delivered.
     */
    public function isComplete(): bool
    {
        $statuses = Task::query()
            ->whereIn('phase_id', $this->descendantIds())
            ->pluck('status');

        return $statuses->isNotEmpty()
            && $statuses->every(fn ($s) => $s === Task::DONE);
    }
}
