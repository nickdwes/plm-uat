<?php

namespace App\Models\Project;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person on a project — a manager, a lead, or a member of a named role.
 * One table for all three, distinguished by role_kind.
 */
class ProjectPerson extends Model
{
    use BelongsToOrganization;

    protected $table = 'project_people';

    public const KIND_MANAGER = 'manager';
    public const KIND_LEAD    = 'lead';
    public const KIND_MEMBER  = 'member';

    // Governance roles — single-person slots on the charter, held in the same
    // table so everyone on the project lives in one place.
    public const KIND_SPONSOR = 'sponsor';
    public const KIND_OWNER   = 'owner';
    public const KIND_PM      = 'project_manager';
    public const KIND_ENGINEER = 'engineer';

    public const KINDS = [
        self::KIND_MANAGER, self::KIND_LEAD, self::KIND_MEMBER,
        self::KIND_SPONSOR, self::KIND_OWNER, self::KIND_PM, self::KIND_ENGINEER,
    ];

    /** The governance slots, in the order they read on the charter. */
    public const GOVERNANCE = [self::KIND_SPONSOR, self::KIND_OWNER, self::KIND_PM, self::KIND_ENGINEER];

    protected $fillable = [
        'organization_id', 'project_id', 'employee_id', 'project_role_id', 'role_kind',
        'is_lead', 'capacity_percent', 'assigned_from', 'assigned_to',
    ];

    protected $casts = [
        'is_lead'          => 'boolean',
        'capacity_percent' => 'integer',
        'assigned_from'    => 'date',
        'assigned_to'      => 'date',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(ProjectRole::class, 'project_role_id');
    }
}
