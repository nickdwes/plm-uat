<?php

namespace App\Models\Recruitment;

use App\Models\Employee;
use App\Models\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One rung of an approval policy.
 *
 * The resolver is the interesting part. `line_manager` is worked out at
 * submission from whoever asked, so it stays right when people move teams —
 * naming a manager explicitly means every reorganisation silently breaks the
 * chain and nobody notices until a requisition sits unapproved for a fortnight.
 */
class RequisitionApprovalStep extends Model
{
    protected $table = 'requisition_approval_steps';

    protected $fillable = [
        'requisition_approval_policy_id', 'position', 'label',
        'resolver', 'role_id', 'employee_id',
    ];

    public const RESOLVERS = ['line_manager', 'role', 'employee'];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(RequisitionApprovalPolicy::class, 'requisition_approval_policy_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
