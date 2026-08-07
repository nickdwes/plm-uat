<?php

namespace App\Models\Task;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An issue put in front of somebody by name.
 *
 * The legacy escalated 212 of its 242 issues, so this is not an exception path
 * — it is most of what raising an issue means, which is why the design puts the
 * toggle inside the raise form rather than behind a second action.
 *
 * Kept as rows rather than a column on the issue: an issue that climbs past one
 * person and on to the next has two escalations, and a single column would lose
 * the first the moment it moved.
 */
class TaskIssueEscalation extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'task_issue_id', 'employee_id', 'raised_by', 'note',
    ];

    public function issue(): BelongsTo
    {
        return $this->belongsTo(TaskIssue::class, 'task_issue_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function raiser(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'raised_by');
    }
}
