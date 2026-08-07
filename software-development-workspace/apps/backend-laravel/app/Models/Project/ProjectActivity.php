<?php

namespace App\Models\Project;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What has happened on a project. */
class ProjectActivity extends Model
{
    use BelongsToOrganization;

    protected $table = 'project_activities';

    protected $fillable = [
        'organization_id', 'project_id', 'project_task_id', 'kind', 'type',
        'body', 'occurred_at', 'employee_id', 'created_by',
    ];

    protected $casts = ['occurred_at' => 'datetime'];

    protected $attributes = ['kind' => 'system', 'type' => 'note'];

    public const TYPES = ['note', 'status', 'task', 'comment', 'attachment', 'assignment'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
