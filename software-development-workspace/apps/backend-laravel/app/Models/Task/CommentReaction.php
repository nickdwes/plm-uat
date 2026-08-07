<?php

namespace App\Models\Task;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Employee;
use App\Models\Project\ProjectComment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's one reaction to one comment.
 *
 * The unique key across comment, person and emoji is the whole mechanism: it
 * makes a reaction something you can take back, and makes double-counting
 * impossible without a tally column that could drift.
 */
class CommentReaction extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'project_comment_id', 'employee_id', 'emoji'];

    public function comment(): BelongsTo
    {
        return $this->belongsTo(ProjectComment::class, 'project_comment_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
