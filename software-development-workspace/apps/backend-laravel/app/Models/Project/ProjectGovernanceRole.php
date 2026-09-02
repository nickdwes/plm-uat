<?php

namespace App\Models\Project;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One seat on a project's governance committee — a defined role with the person
 * who holds it, their responsibility, escalation and approval authority, and a
 * named backup.
 */
class ProjectGovernanceRole extends Model
{
    use BelongsToOrganization;

    protected $table = 'project_governance';

    /**
     * The committee, in seniority order. key => label. Fixed catalogue so a
     * project's governance reads the same everywhere and the reports can rely
     * on it.
     */
    public const CATALOG = [
        'executive_sponsor'      => 'Executive Sponsor',
        'project_owner'          => 'Project Owner',
        'project_director'       => 'Project Director',
        'project_manager'        => 'Project Manager',
        'project_finance_manager'=> 'Project Finance Manager',
        'presales_engineer'      => 'Presales Engineer',
        'solution_architect'     => 'Solution Architect',
        'project_engineer'       => 'Project Engineer',
        'technical_lead'         => 'Technical Lead',
        'service_delivery_manager' => 'Service Delivery Manager',
        'logistics_coordinator'  => 'Logistics Coordinator',
        'procurement_lead'       => 'Procurement Lead',
        'commercial_manager'     => 'Commercial Manager',
        'quality_compliance'     => 'Quality & Compliance Representative',
        'resource_manager'       => 'Resource Manager',
    ];

    /**
     * The seats a project should not start without. Finance Manager, Presales
     * and Logistics are named by the governance spec; the sponsor and PM are the
     * two nobody disputes. A vacancy in any of these warns before initiation.
     */
    public const MANDATORY = [
        'executive_sponsor', 'project_manager', 'project_finance_manager',
        'presales_engineer', 'logistics_coordinator',
    ];

    protected $fillable = [
        'organization_id', 'project_id', 'role_key',
        'assignee_employee_id', 'backup_employee_id',
        'responsibility', 'escalation_authority', 'approval_authority',
        'department', 'contact_info', 'start_date', 'end_date',
        'is_mandatory', 'position', 'created_by',
    ];

    protected $casts = [
        'is_mandatory' => 'boolean', 'position' => 'integer',
        'start_date' => 'date', 'end_date' => 'date',
    ];

    public function label(): string
    {
        return self::CATALOG[$this->role_key] ?? $this->role_key;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assignee_employee_id');
    }

    public function backup(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'backup_employee_id');
    }
}
