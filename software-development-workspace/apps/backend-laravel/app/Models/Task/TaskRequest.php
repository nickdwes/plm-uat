<?php

namespace App\Models\Task;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Asking somebody to take part, rather than assigning them.
 *
 * The legacy had three tables of identical shape — helping (2,566 rows),
 * delegations (354) and collaborations (9). One table with a `kind`, because
 * three copies of the same eight columns is how two of them end up missing a
 * feature the third gained.
 *
 * The data is worth reading. Helping was accepted 98% of the time and plainly
 * works. Delegation sat at 74% still Pending — a workflow nobody answered,
 * which is a finding rather than a reason to drop it: what was missing was a
 * screen showing somebody they had been asked.
 */
class TaskRequest extends Model
{
    use BelongsToOrganization;

    protected $table = 'task_requests';

    protected $fillable = [
        'organization_id', 'task_id', 'kind', 'to_employee_id', 'requested_by',
        'request_comment', 'status', 'response_comment', 'responded_at',
    ];

    protected $casts = ['responded_at' => 'datetime'];

    protected $attributes = ['status' => 'pending'];

    /**
     * Assist keeps the task with its owner; delegate hands it over;
     * collaborate is joint. Only delegate changes who is accountable.
     */
    public const KINDS = ['assist', 'delegate', 'collaborate'];

    public const STATUSES = ['pending', 'accepted', 'declined'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function toEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'to_employee_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requested_by');
    }

    public function getIsPendingAttribute(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Asked and never answered.
     *
     * The legacy's delegations sat like this 74% of the time with nothing
     * surfacing it. A week is the point at which silence stops being a delay
     * and starts being a no.
     */
    public function getIsStaleAttribute(): bool
    {
        return $this->is_pending
            && $this->created_at !== null
            && $this->created_at->lt(now()->subWeek());
    }

    public function scopeAwaiting(Builder $q): Builder
    {
        return $q->where('status', 'pending');
    }
}
