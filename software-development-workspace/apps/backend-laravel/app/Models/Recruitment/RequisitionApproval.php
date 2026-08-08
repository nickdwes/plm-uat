<?php

namespace App\Models\Recruitment;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's place in the chain, for one requisition.
 *
 * Copied from the policy at submission rather than read through it, so
 * rewriting the policy next quarter cannot rewrite who approved what last
 * quarter. The label is copied for the same reason — "HR" meant a particular
 * thing on the day.
 */
class RequisitionApproval extends Model
{
    use BelongsToOrganization;

    protected $table = 'requisition_approvals';

    protected $fillable = [
        'organization_id', 'talent_requisition_id', 'position', 'label',
        'employee_id', 'status', 'reason', 'actioned_at',
    ];

    protected $casts = ['actioned_at' => 'datetime'];

    public const STATUSES = ['pending', 'approved', 'held', 'declined'];

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(TalentRequisition::class, 'talent_requisition_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function scopeAwaiting(Builder $q): Builder
    {
        return $q->where('status', 'pending');
    }
}
