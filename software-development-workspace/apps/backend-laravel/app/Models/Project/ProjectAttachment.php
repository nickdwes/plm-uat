<?php

namespace App\Models\Project;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A resource on a project or a task. Encrypted through the vault. */
class ProjectAttachment extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'project_attachments';

    protected $fillable = [
        'organization_id', 'project_id', 'project_task_id', 'document_type',
        'label', 'original_name', 'storage_path', 'mime_type', 'size_bytes', 'uploaded_by',
    ];

    protected $casts = ['size_bytes' => 'integer'];

    public const DOCUMENT_TYPES = ['client', 'internal', 'financial', 'technical'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'project_task_id');
    }
}
