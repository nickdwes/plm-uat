<?php

namespace App\Models\Task;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Project\ProjectAttachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A document the task cannot close without.
 *
 * Named up front and satisfied later — the legacy required 344 and got 247.
 * The gap is the point: "this needs a signed ToR" is a checklist item whether
 * or not the document exists yet, and a task list that cannot say so loses the
 * requirement the moment somebody forgets.
 */
class TaskRequiredDocument extends Model
{
    use BelongsToOrganization;

    protected $table = 'task_required_documents';

    protected $fillable = [
        'organization_id', 'task_id', 'name',
        'project_attachment_id', 'fulfilled_at', 'created_by',
        'original_name', 'storage_path', 'mime_type', 'size_bytes', 'uploaded_by',
    ];

    protected $casts = ['fulfilled_at' => 'datetime'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(ProjectAttachment::class, 'project_attachment_id');
    }

    /**
     * Satisfied either by its own file or by a project attachment.
     *
     * Reading `fulfilled_at` rather than either column, so the two routes give
     * the same answer and neither can be satisfied without stamping when.
     */
    public function getIsFulfilledAttribute(): bool
    {
        return $this->fulfilled_at !== null;
    }
}
