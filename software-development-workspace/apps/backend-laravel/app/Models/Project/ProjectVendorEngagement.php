<?php

namespace App\Models\Project;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Task\Task;
use App\Models\Training\VendorPartner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A demand for an external party raised by a task requirement — a vendor, a
 * professional-services resource, or a subcontractor — tracked from identifying
 * a supplier through to active. Distinct from the project's PS / subcontractor
 * roster: that is who is engaged overall, this is what a task asked for.
 */
class ProjectVendorEngagement extends Model
{
    use BelongsToOrganization;

    protected $table = 'project_vendor_engagements';

    public const TYPES = ['external_vendor', 'professional_services', 'subcontractor'];

    public const STATUS_IDENTIFYING = 'identifying';
    public const STATUS_ENGAGED     = 'engaged';
    public const STATUS_ACTIVE      = 'active';
    public const STATUS_CLOSED      = 'closed';
    public const STATUSES = [self::STATUS_IDENTIFYING, self::STATUS_ENGAGED, self::STATUS_ACTIVE, self::STATUS_CLOSED];

    /** The prerequisite type each engagement type is raised from. */
    public const FROM_PREREQ = [
        'external_vendor'       => 'external_vendor',
        'professional_services' => 'professional_services',
        'subcontractor'         => 'subcontractor',
    ];

    protected $fillable = [
        'organization_id', 'project_id', 'task_id', 'reference',
        'engagement_type', 'vendor_partner_id', 'vendor_name', 'scope',
        'status', 'notes', 'created_by',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function vendorPartner(): BelongsTo
    {
        return $this->belongsTo(VendorPartner::class, 'vendor_partner_id');
    }
}
