<?php

namespace App\Models\Task;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One actionable prerequisite generated from a task's logistics flags — a
 * checklist item a PM clears before the task starts, tracked with a status.
 */
class TaskPrerequisite extends Model
{
    use BelongsToOrganization;

    protected $table = 'task_prerequisites';

    public const STATUS_OPEN        = 'open';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_BLOCKED     = 'blocked';
    public const STATUS_DONE        = 'done';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_IN_PROGRESS, self::STATUS_BLOCKED, self::STATUS_DONE];

    /**
     * The prerequisite each logistics flag generates: flag => [type, label].
     * `requires_approval` adds the 'approval' type on top of these.
     */
    public const FROM_FLAG = [
        'needs_travel'                => ['travel', 'Travel arrangement & authorization'],
        'needs_client_access'         => ['client_access', 'Client site access'],
        'needs_accommodation'         => ['accommodation', 'Accommodation'],
        'needs_asset'                 => ['asset', 'Asset / equipment allocation'],
        'needs_software'              => ['software', 'Software / licence allocation'],
        'needs_procurement'           => ['procurement', 'Procurement'],
        'needs_external_vendors'      => ['external_vendor', 'External vendor engagement'],
        'needs_professional_services' => ['professional_services', 'Professional services engagement'],
        'needs_subcontractors'        => ['subcontractor', 'Contractor / subcontractor engagement'],
    ];

    protected $fillable = [
        'organization_id', 'task_id', 'project_id',
        'type', 'label', 'status', 'detail', 'procurement_needed',
        'due_on', 'reminded_at', 'linked_type', 'linked_id',
        'resolved_at', 'resolved_by', 'created_by',
    ];

    protected $casts = [
        'procurement_needed' => 'boolean',
        'due_on'             => 'date',
        'reminded_at'        => 'datetime',
        'resolved_at'        => 'datetime',
    ];

    protected $attributes = ['status' => self::STATUS_OPEN, 'procurement_needed' => false];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
