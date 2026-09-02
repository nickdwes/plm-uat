<?php

namespace App\Models\Project;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One inbound email pulled from a project's shared mailbox. */
class ProjectEmail extends Model
{
    use BelongsToOrganization;

    protected $table = 'project_emails';

    protected $fillable = [
        'organization_id', 'project_id', 'graph_id', 'internet_message_id',
        'from_name', 'from_email', 'subject', 'body_preview', 'body_html',
        'to_recipients', 'cc_recipients', 'has_attachments', 'received_at',
    ];

    protected $casts = [
        'to_recipients'   => 'array',
        'cc_recipients'   => 'array',
        'has_attachments' => 'boolean',
        'received_at'     => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
