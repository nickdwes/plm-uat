<?php

namespace App\Models\Task;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A task's requirements and logistics — what has to be arranged, at what cost
 * and lead time, and whether it needs approval, before the work can run.
 */
class TaskLogistics extends Model
{
    use BelongsToOrganization;

    protected $table = 'task_logistics';

    /** The requirement flags, in display order. */
    public const NEEDS = [
        'needs_travel', 'needs_client_access', 'needs_accommodation', 'needs_asset',
        'needs_software', 'needs_procurement', 'needs_external_vendors',
        'needs_professional_services', 'needs_subcontractors',
    ];

    protected $fillable = [
        'organization_id', 'task_id', 'project_id',
        'needs_travel', 'needs_client_access', 'needs_accommodation', 'needs_asset',
        'needs_software', 'needs_procurement', 'needs_external_vendors',
        'needs_professional_services', 'needs_subcontractors',
        'travel_location', 'travel_mode', 'details', 'requires_approval', 'created_by',
    ];

    protected $casts = [
        'needs_travel'                => 'boolean',
        'needs_client_access'         => 'boolean',
        'needs_accommodation'         => 'boolean',
        'needs_asset'                 => 'boolean',
        'needs_software'              => 'boolean',
        'needs_procurement'           => 'boolean',
        'needs_external_vendors'      => 'boolean',
        'needs_professional_services' => 'boolean',
        'needs_subcontractors'        => 'boolean',
        'requires_approval'           => 'boolean',
    ];

    /** Whether the task requires anything at all beyond the work itself. */
    public function hasAnyRequirement(): bool
    {
        foreach (self::NEEDS as $flag) {
            if ($this->{$flag}) {
                return true;
            }
        }

        return false;
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
