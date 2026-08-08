<?php

namespace App\Models\Recruitment;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Department;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Asking for a role before advertising one.
 *
 * Not a draft vacancy. A role costs money and headcount, so somebody asks, two
 * or three people agree, and only then is it advertised — a vacancy created
 * directly is that whole conversation skipped. The requisition keeps its own
 * record afterwards: who asked, who agreed, who held it and why.
 */
class TalentRequisition extends Model
{
    use BelongsToOrganization, HasUuids, SoftDeletes;

    protected $table = 'talent_requisitions';

    protected $fillable = [
        'organization_id', 'uuid', 'reference',
        'job_title', 'department_id', 'positions', 'priority',
        'target_hire_date', 'employment_type', 'period',
        'job_summary', 'key_responsibilities', 'required_qualifications', 'skills',
        'status', 'requested_by', 'decision_reason', 'vacancy_id', 'created_by',
    ];

    protected $casts = [
        'target_hire_date' => 'date',
        'skills'           => 'array',
        'positions'        => 'integer',
    ];

    protected $attributes = ['status' => 'draft', 'priority' => 'medium', 'positions' => 1];

    /** The uuid is a secondary key; routes and joins still use the id. */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getIncrementing(): bool
    {
        return true;
    }

    public function getKeyType(): string
    {
        return 'int';
    }

    /**
     * Where the request has got to.
     *
     * `declined` and `closed` are apart: one is a refusal, the other a role
     * that was filled or withdrawn. Reporting that mixes them cannot tell a
     * hiring freeze from a successful year.
     */
    public const STATUSES = [
        'draft', 'in_approval', 'approved', 'open', 'on_hold', 'declined', 'closed',
    ];

    public const PRIORITIES = ['low', 'medium', 'high'];

    public const EMPLOYMENT_TYPES = ['permanent', 'contract', 'temporary', 'intern', 'consultant'];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requested_by');
    }

    public function vacancy(): BelongsTo
    {
        return $this->belongsTo(Vacancy::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(RequisitionApproval::class)->orderBy('position');
    }

    public function events(): HasMany
    {
        return $this->hasMany(RequisitionEvent::class)->orderBy('occurred_at');
    }

    // ── Derived ───────────────────────────────────────────────────────────

    /**
     * The step waiting on somebody.
     *
     * The first pending one, because the chain is sequential — a CTO approving
     * something HR has not seen is not a chain, it is three people with
     * opinions.
     */
    public function currentApproval(): ?RequisitionApproval
    {
        return $this->approvals->firstWhere('status', 'pending');
    }

    /** Advertised or advertisable — the request has been agreed. */
    public function getIsApprovedAttribute(): bool
    {
        return in_array($this->status, ['approved', 'open', 'closed'], true);
    }

    /** Still needing a decision from somebody. */
    public function scopeAwaitingDecision(Builder $q): Builder
    {
        return $q->whereIn('status', ['in_approval', 'on_hold']);
    }
}
