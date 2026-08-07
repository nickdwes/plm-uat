<?php

namespace App\Models\Project;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A payment the customer agreed to make against a piece of work.
 *
 * The status set is ours, not the legacy's — its column held 'Pending' on all
 * 82 rows. These are the states a milestone genuinely passes through, and the
 * two that matter are `invoiced` and `paid`: the gap between them is the money
 * owed, which is the only question this table is really asked.
 */
class ProjectContractMilestone extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'project_contract_milestones';

    protected $fillable = [
        'organization_id', 'project_id', 'project_task_id', 'reference',
        'name', 'description', 'currency', 'value', 'status',
        'due_on', 'invoiced_at', 'paid_at', 'invoice_reference', 'notes', 'created_by',
    ];

    protected $casts = [
        'value'       => 'decimal:2',
        'due_on'      => 'date',
        'invoiced_at' => 'datetime',
        'paid_at'     => 'datetime',
    ];

    protected $attributes = ['status' => 'pending'];

    public const STATUSES = ['pending', 'invoiced', 'paid', 'disputed', 'waived'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** The work it is paid against — a legacy plan phase, now a task. */
    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'project_task_id');
    }

    // ── Derived ───────────────────────────────────────────────────────────

    public function getIsPaidAttribute(): bool
    {
        return $this->status === 'paid';
    }

    /**
     * Invoiced and still unpaid — the money actually owed.
     *
     * Distinct from overdue: an invoice issued last week is outstanding but not
     * late, and reporting the two as one number is how a receivables figure
     * stops being trusted.
     */
    public function getIsOutstandingAttribute(): bool
    {
        return $this->status === 'invoiced';
    }

    /** Past its due date and not paid. Waived and disputed are neither. */
    public function getIsOverdueAttribute(): bool
    {
        return in_array($this->status, ['pending', 'invoiced'], true)
            && $this->due_on !== null
            && $this->due_on->isPast();
    }

    /**
     * Whether the work it is paid against is finished.
     *
     * The reason a milestone points at a task at all: billing for something
     * not yet delivered is a conversation, and one nobody wants to discover
     * from the customer.
     */
    public function getWorkIsDoneAttribute(): ?bool
    {
        return $this->task === null ? null : $this->task->is_done;
    }

    // ── Scopes ────────────────────────────────────────────────────────────

    public function scopeOutstanding(Builder $q): Builder
    {
        return $q->where('status', 'invoiced');
    }

    /**
     * Billable — the work is done and it has not been invoiced.
     *
     * The question this table exists for, and one the legacy could not answer
     * because its status never moved off 'Pending'.
     */
    public function scopeBillable(Builder $q): Builder
    {
        return $q->where('status', 'pending')
            ->whereHas('task', fn ($t) => $t->where('status', ProjectTask::DONE));
    }
}
