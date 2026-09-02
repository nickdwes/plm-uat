<?php

namespace App\Models\Project;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A subcontractor firm engaged on a project — the company, its scope, contract
 * reference, and where its approval and onboarding stand.
 */
class ProjectSubcontractor extends Model
{
    use BelongsToOrganization;

    protected $table = 'project_subcontractors';

    /** The contractor-type catalogue. key => label. */
    public const CATALOG = [
        'cabling'         => 'Cabling Contractor',
        'electrical'      => 'Electrical Contractor',
        'civil_works'     => 'Civil Works Contractor',
        'network'         => 'Network Contractor',
        'security'        => 'Security Contractor',
        'ict'             => 'ICT Contractor',
        'field_technician'=> 'Field Technician',
        'specialist'      => 'Specialist Contractor',
    ];

    public const APPROVAL_STATUSES   = ['pending', 'approved', 'rejected'];
    public const ONBOARDING_STATUSES = ['not_started', 'in_progress', 'complete'];

    protected $fillable = [
        'organization_id', 'project_id', 'role', 'company', 'contact_person', 'contact_info',
        'scope_of_work', 'contract_reference', 'approval_status', 'onboarding_status',
        'start_date', 'end_date', 'position', 'created_by',
    ];

    protected $casts = ['start_date' => 'date', 'end_date' => 'date'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
