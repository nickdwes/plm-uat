<?php

namespace App\Models\Support;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A ticket's Root Cause Analysis — one per ticket.
 */
class TicketRca extends Model
{
    use BelongsToOrganization;

    protected $table = 'support_ticket_rcas';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_FINAL = 'final';

    public const CATEGORIES = [
        'Application', 'Infrastructure', 'Network', 'Database', 'Security',
        'Configuration', 'Deployment', 'Process', 'Human error', 'Vendor',
        'Capacity', 'Monitoring', 'Change management',
    ];

    protected $fillable = [
        'organization_id', 'support_ticket_id',
        'title', 'incident_date', 'prepared_date', 'system_affected', 'business_unit',
        'severity', 'incident_owner', 'technical_owner', 'reviewers',
        'executive_summary', 'incident_description', 'business_impact', 'timeline',
        'technical_investigation',
        'immediate_cause', 'root_cause', 'contributing_factors', 'control_failure',
        'five_whys', 'final_root_cause', 'root_cause_category',
        'resolution', 'corrective_actions', 'preventive_actions',
        'lessons_learned', 'evidence', 'risk_assessment',
        'status', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'incident_date'          => 'date',
        'prepared_date'          => 'date',
        'reviewers'              => 'array',
        'incident_description'   => 'array',
        'business_impact'        => 'array',
        'timeline'                => 'array',
        'five_whys'               => 'array',
        'corrective_actions'      => 'array',
        'preventive_actions'      => 'array',
        'risk_assessment'         => 'array',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'support_ticket_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function evidenceFiles(): HasMany
    {
        return $this->hasMany(RcaEvidenceFile::class, 'ticket_rca_id');
    }
}
