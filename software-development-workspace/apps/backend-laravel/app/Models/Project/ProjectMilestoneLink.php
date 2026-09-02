<?php

namespace App\Models\Project;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Task\Task;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One piece of work a payment milestone waits on — a task or a phase. All of a
 * milestone's links must be complete before it is earned and right for payment.
 */
class ProjectMilestoneLink extends Model
{
    use BelongsToOrganization;

    protected $table = 'project_milestone_links';

    protected $fillable = ['organization_id', 'milestone_id', 'task_id', 'phase_id'];

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(ProjectContractMilestone::class, 'milestone_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(ProjectPhase::class, 'phase_id');
    }
}
