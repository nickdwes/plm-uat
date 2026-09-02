<?php

namespace App\Models\Project;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A risk on the register: how likely, how bad, and what we are doing about it.
 * Likelihood × impact gives the heat an audit expects to see ranked.
 */
class ProjectRisk extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'project_risks';

    public const LEVELS = ['low', 'medium', 'high'];

    public const STATUS_OPEN       = 'open';
    public const STATUS_MITIGATING = 'mitigating';
    public const STATUS_CLOSED     = 'closed';
    public const STATUSES = [self::STATUS_OPEN, self::STATUS_MITIGATING, self::STATUS_CLOSED];

    protected $fillable = [
        'organization_id', 'project_id', 'task_id', 'phase_id',
        'title', 'description', 'likelihood', 'impact',
        'status', 'mitigation', 'owner_employee_id',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'owner_employee_id');
    }

    /** The work this risk bears on. */
    public function task(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Task\Task::class, 'task_id');
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(ProjectPhase::class, 'phase_id');
    }

    /** Evidence behind the risk — assessments, correspondence, screenshots. */
    public function evidence(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProjectAttachment::class, 'risk_id')->latest('id');
    }

    /** low(1)…high(3) on each axis, multiplied — 1..9, for ranking the heat. */
    public function score(): int
    {
        $rank = ['low' => 1, 'medium' => 2, 'high' => 3];
        return ($rank[$this->likelihood] ?? 2) * ($rank[$this->impact] ?? 2);
    }
}
