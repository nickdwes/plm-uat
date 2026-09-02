<?php

namespace App\Models\Task;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stretch where the task was not ours to move.
 *
 * Work stops all the time — waiting on a client's signature, a supplier's
 * delivery, somebody's approval. With nowhere to record it the whole wait was
 * counted against us, so a task held six weeks by a customer read exactly like
 * one nobody had touched.
 *
 * Open-ended by design: `resumed_at` is null while the wait is still running,
 * which is also how the current pause is found.
 */
class TaskPause extends Model
{
    use BelongsToOrganization;

    protected $table = 'task_pauses';

    /**
     * Who the wait is on, and what is being waited for.
     *
     * The family is what a report groups by — "how much of this quarter was
     * spent waiting on customers" — and the reason is what a person reads.
     */
    public const REASONS = [
        'pending_customer' => [
            'label'   => 'Pending on customer',
            'party'   => 'customer',
            'reasons' => ['awaiting_approval', 'awaiting_information', 'awaiting_feedback', 'awaiting_payment', 'awaiting_signoff'],
        ],
        'pending_internal' => [
            'label'   => 'Pending internally',
            'party'   => 'internal_team',
            'reasons' => ['awaiting_management_approval', 'awaiting_procurement', 'awaiting_resource_allocation', 'awaiting_technical_review'],
        ],
        'pending_vendor' => [
            'label'   => 'Pending vendor',
            'party'   => 'vendor',
            'reasons' => ['awaiting_delivery', 'awaiting_support_response', 'awaiting_licensing', 'awaiting_implementation'],
        ],
        'pending_subcontractor' => [
            'label'   => 'Pending subcontractor',
            'party'   => 'subcontractor',
            'reasons' => ['awaiting_execution', 'awaiting_documentation', 'awaiting_site_works'],
        ],
    ];

    protected $fillable = [
        'organization_id', 'task_id', 'category', 'reason', 'note',
        'expected_resume_on', 'responsible_party', 'task_issue_id',
        'paused_by', 'paused_at', 'resumed_by', 'resumed_at', 'resume_note',
    ];

    protected $casts = [
        'expected_resume_on' => 'date',
        'paused_at'          => 'datetime',
        'resumed_at'         => 'datetime',
    ];

    /** Every reason across every family, for validation. */
    public static function allReasons(): array
    {
        return array_merge(...array_column(self::REASONS, 'reasons'));
    }

    /** Which family a reason belongs to, so the caller need only name one. */
    public static function categoryFor(string $reason): ?string
    {
        foreach (self::REASONS as $category => $spec) {
            if (in_array($reason, $spec['reasons'], true)) {
                return $category;
            }
        }

        return null;
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereNull('resumed_at');
    }

    /** How long this wait ran, or has run so far. */
    public function minutes(): int
    {
        return (int) $this->paused_at->diffInMinutes($this->resumed_at ?? now());
    }

    public function isOpen(): bool
    {
        return $this->resumed_at === null;
    }

    /** Past the date it was expected back, and still waiting. */
    public function isOverdue(): bool
    {
        return $this->isOpen()
            && $this->expected_resume_on !== null
            && $this->expected_resume_on->isPast();
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function pauser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paused_by');
    }

    public function resumer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resumed_by');
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(TaskIssue::class, 'task_issue_id');
    }
}
