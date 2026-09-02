<?php

namespace App\Models\Project;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A dated delivery checkpoint: a deliverable and its target date. Progress
 * status is set by hand (planned → in_progress → achieved); "at risk" and
 * "overdue" are derived from the target date, never stored — the same rule the
 * rest of the module follows so a date change can't leave a stale flag behind.
 */
class ProjectDeliveryMilestone extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'project_delivery_milestones';

    public const STATUS_PLANNED     = 'planned';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_ACHIEVED    = 'achieved';
    public const STATUSES = [self::STATUS_PLANNED, self::STATUS_IN_PROGRESS, self::STATUS_ACHIEVED];

    protected $fillable = [
        'organization_id', 'project_id',
        'title', 'description', 'target_date', 'status', 'achieved_on',
        'position', 'owner_employee_id', 'created_by',
    ];

    protected $casts = [
        'target_date' => 'date',
        'achieved_on' => 'date',
        'position'    => 'integer',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'owner_employee_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getIsAchievedAttribute(): bool
    {
        return $this->status === self::STATUS_ACHIEVED;
    }

    /** Past its target date and not yet achieved. */
    public function getIsOverdueAttribute(): bool
    {
        return ! $this->is_achieved
            && $this->target_date !== null
            && $this->target_date->isPast();
    }

    /** Due within a week and not achieved — worth a heads-up, not yet late. */
    public function getIsAtRiskAttribute(): bool
    {
        return ! $this->is_achieved
            && ! $this->is_overdue
            && $this->target_date !== null
            && $this->target_date->lte(Carbon::today()->addWeek());
    }
}
