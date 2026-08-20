<?php

namespace App\Models\Support;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person, one decision, on the record.
 *
 * "Awaiting Approval" and "Purchase Order Approval" existed in the old module
 * as words on a ticket. Nothing asked anybody, nothing recorded an answer, and
 * nothing stopped the work starting anyway.
 */
class Approval extends Model
{
    use BelongsToOrganization;

    protected $table = 'support_approvals';

    public const PENDING  = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    /** Not required after all — a stage that stopped applying, not a decision. */
    public const SKIPPED = 'skipped';

    protected $fillable = [
        'organization_id', 'approvable_type', 'approvable_id',
        'stage', 'sequence', 'expected_approver_id', 'decided_by',
        'status', 'comment', 'decided_at',
    ];

    protected $casts = ['sequence' => 'integer', 'decided_at' => 'datetime'];

    protected $attributes = ['status' => self::PENDING];

    public function approvable()
    {
        return $this->morphTo();
    }

    public function expectedApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'expected_approver_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }
}
