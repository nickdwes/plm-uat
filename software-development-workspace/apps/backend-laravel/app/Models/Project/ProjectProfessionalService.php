<?php

namespace App\Models\Project;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A professional-services resource on a project — a consultant, SME, trainer or
 * advisory engineer, internal or from a provider.
 */
class ProjectProfessionalService extends Model
{
    use BelongsToOrganization;

    protected $table = 'project_professional_services';

    /** The advisory role catalogue. key => label. */
    public const CATALOG = [
        'consultant'               => 'Consultant',
        'solution_consultant'      => 'Solution Consultant',
        'implementation_consultant'=> 'Implementation Consultant',
        'resident_engineer'        => 'Resident Engineer',
        'technical_advisor'        => 'Technical Advisor',
        'trainer'                  => 'Trainer',
        'sme'                      => 'Subject Matter Expert (SME)',
    ];

    protected $fillable = [
        'organization_id', 'project_id', 'role', 'name', 'assignee_employee_id',
        'organization', 'description', 'contact_info', 'start_date', 'end_date',
        'position', 'created_by',
    ];

    protected $casts = ['start_date' => 'date', 'end_date' => 'date'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assignee_employee_id');
    }
}
