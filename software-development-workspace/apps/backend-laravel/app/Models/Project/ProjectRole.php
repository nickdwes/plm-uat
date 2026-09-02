<?php

namespace App\Models\Project;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named role bucket on a project (Project Management, Tech Lead, …).
 */
class ProjectRole extends Model
{
    use BelongsToOrganization;

    protected $table = 'project_roles';

    /** Default buckets — the old trio plus Presales, seeded on demand. */
    public const DEFAULTS = ['Project Management', 'Tech Lead', 'Implementation Engineers', 'Presales'];

    /** The ten standing delivery teams. key => [name, description]. */
    public const TEAM_CATALOG = [
        'project_management' => ['Project Management Team', 'Plans, coordinates and reports delivery.'],
        'engineering'        => ['Engineering Team', 'Solution design and engineering.'],
        'implementation'     => ['Implementation Team', 'On-site build, configuration and cutover.'],
        'support'            => ['Support Team', 'Operational support and handover.'],
        'logistics'          => ['Logistics Team', 'Movement of people and equipment.'],
        'procurement'        => ['Procurement Team', 'Sourcing of hardware, software and services.'],
        'finance'            => ['Finance Team', 'Budget, billing and cost control.'],
        'presales'           => ['Presales Team', 'Solutioning and proposal support.'],
        'vendor_management'  => ['Vendor Management Team', 'OEM and subcontractor coordination.'],
        'quality_compliance' => ['Quality & Compliance Team', 'Assurance, standards and sign-off.'],
    ];

    protected $fillable = ['organization_id', 'project_id', 'name', 'team_key', 'description', 'position'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function people(): HasMany
    {
        return $this->hasMany(ProjectPerson::class);
    }
}
