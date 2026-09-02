<?php

namespace App\Models\Pipeline;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One message from the org's mailbox that involves one of this lead's people. */
class LeadEmail extends Model
{
    use BelongsToOrganization;

    protected $table = 'lead_emails';

    protected $fillable = [
        'organization_id', 'lead_id', 'graph_id', 'internet_message_id',
        'matched_address', 'from_name', 'from_email', 'subject', 'body_preview',
        'body_html', 'to_recipients', 'cc_recipients', 'has_attachments', 'received_at',
    ];

    protected $casts = [
        'to_recipients'   => 'array',
        'cc_recipients'   => 'array',
        'has_attachments' => 'boolean',
        'received_at'     => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
