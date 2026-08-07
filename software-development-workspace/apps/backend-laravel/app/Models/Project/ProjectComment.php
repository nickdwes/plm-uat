<?php

namespace App\Models\Project;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A comment on a project or one of its tasks, threaded one level.
 *
 * Capped at one level deliberately: the design shows replies under a comment
 * and no deeper, unbounded nesting reads badly in a narrow column, and nobody
 * has ever asked for the fourth level.
 */
class ProjectComment extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'project_comments';

    protected $fillable = [
        'organization_id', 'project_id', 'task_id', 'parent_id',
        'body', 'employee_id', 'created_by',
    ];

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->oldest('id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
