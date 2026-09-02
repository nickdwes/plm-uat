<?php

namespace App\Models\Procurement;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\RecordsAuditTrail;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stage of a purchase's approval chain, materialised when the request is
 * submitted. Expected approver and actual decider are kept apart: who should
 * have acted is a governance question, who did is an audit one.
 */
class ProcurementApproval extends Model
{
    use BelongsToOrganization, RecordsAuditTrail;

    protected array $auditExclude = ['updated_at'];

    protected $fillable = [
        'organization_id', 'procurement_request_id', 'stage', 'sequence',
        'expected_approver_id', 'status', 'escalated', 'decided_by', 'comment', 'decided_at',
    ];

    protected $casts = [
        'sequence'   => 'integer',
        'escalated'  => 'boolean',
        'decided_at' => 'datetime',
    ];

    protected $attributes = ['status' => 'pending', 'escalated' => false];

    public function request(): BelongsTo
    {
        return $this->belongsTo(ProcurementRequest::class, 'procurement_request_id');
    }

    public function expectedApprover(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'expected_approver_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
