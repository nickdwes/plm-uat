<?php

namespace App\Models\Task;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * A blocker written down rather than linked.
 *
 * The same table as task-to-task dependencies, read through a model because a
 * row with no second task is not a pivot — it is a note, and belongsToMany
 * cannot see it.
 *
 * 172 of the legacy's 203 dependencies were exactly this. They are the ones
 * outside our control, which is why they were worth writing down at all.
 */
class TaskDependencyNote extends Model
{
    use BelongsToOrganization;

    protected $table = 'task_dependencies';

    protected $fillable = ['organization_id', 'task_id', 'depends_on_task_id', 'kind', 'note'];
}
