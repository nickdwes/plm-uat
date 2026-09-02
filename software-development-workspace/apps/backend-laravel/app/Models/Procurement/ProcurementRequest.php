<?php

namespace App\Models\Procurement;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Project\Project;
use App\Models\Task\Task;
use App\Models\Training\VendorPartner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line in the procurement register — an item to buy for a project, asset or
 * consumable, with its vendor, timing, cost and approval state.
 */
class ProcurementRequest extends Model
{
    use BelongsToOrganization;

    protected $table = 'procurement_requests';

    public const CONTEXTS = ['project', 'asset', 'consumable'];
    public const CATEGORIES = ['hardware', 'software', 'licence', 'subscription', 'service', 'equipment', 'consumable', 'other'];
    public const APPROVAL_STATUSES = ['pending', 'approved', 'rejected'];
    public const STATUSES = ['requested', 'ordered', 'received', 'cancelled'];

    /** Days beyond which an item is treated as long-lead (schedule risk). */
    public const LONG_LEAD_DAYS = 30;

    /** The purchase's own journey, distinct from the legacy summary columns. */
    public const LIFECYCLE_STAGES = [
        'draft', 'submitted', 'approved', 'rfq', 'quoted', 'awarded',
        'po_issued', 'delivering', 'received', 'invoiced', 'paid', 'closed',
        'rejected', 'cancelled',
    ];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    /** What the budget gate concluded when it last ran. */
    public const BUDGET_OK = 'ok';
    public const BUDGET_EXCEEDED = 'exceeded';
    public const BUDGET_NONE = 'no_budget';
    public const BUDGET_OVERRIDDEN = 'overridden';

    protected $fillable = [
        'organization_id', 'reference', 'context', 'project_id', 'task_id', 'department_id',
        'item', 'category', 'quantity', 'unit', 'vendor', 'vendor_partner_id',
        'required_by', 'lead_time_days', 'estimated_cost', 'currency',
        'approval_status', 'decided_by', 'decided_at', 'status', 'notes', 'created_by',
        'business_justification', 'priority',
        'budget_checked_at', 'budget_available_amount', 'budget_committed_amount', 'budget_result',
        'lifecycle_stage', 'submitted_at', 'awarded_vendor_id', 'awarded_amount', 'closed_at', 'closed_by',
    ];

    protected $casts = [
        'required_by'    => 'date',
        'estimated_cost' => 'decimal:2',
        'quantity'       => 'integer',
        'lead_time_days' => 'integer',
        'decided_at'     => 'datetime',
        'budget_checked_at'       => 'datetime',
        'budget_available_amount' => 'decimal:2',
        'budget_committed_amount' => 'decimal:2',
        'awarded_amount'          => 'decimal:2',
        'submitted_at'            => 'datetime',
        'closed_at'               => 'datetime',
    ];

    protected $attributes = [
        'context'         => 'project',
        'quantity'        => 1,
        'currency'        => 'KES',
        'approval_status' => 'pending',
        'status'          => 'requested',
        'lifecycle_stage' => 'draft',
        'priority'        => 'normal',
    ];

    /** The materialised approval chain, earliest stage first. */
    public function approvals(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProcurementApproval::class)->orderBy('sequence');
    }

    /** The stage currently awaiting a decision, if any. */
    public function pendingApproval(): ?ProcurementApproval
    {
        return $this->approvals()->where('status', 'pending')->orderBy('sequence')->first();
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Department::class);
    }

    /** The sourcing round, if this purchase went out to quote. */
    public function rfq(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Rfq::class);
    }

    public function awardedVendor(): BelongsTo
    {
        return $this->belongsTo(VendorPartner::class, 'awarded_vendor_id');
    }

    /** A closed purchase is settled history — nothing on it may change. */
    public function isClosed(): bool
    {
        return $this->lifecycle_stage === 'closed';
    }

    /** The money this request commits: what was awarded, else what was asked. */
    public function committedAmount(): float
    {
        return (float) ($this->awarded_amount ?? $this->estimated_cost ?? 0);
    }

    public function isLongLead(): bool
    {
        return $this->lead_time_days !== null && $this->lead_time_days >= self::LONG_LEAD_DAYS;
    }

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

    public function decider(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'decided_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }
}
