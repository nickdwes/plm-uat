<?php

namespace App\Models\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file that came with an email.
 *
 * Recorded when the message is, downloaded after. A row with no storage path is
 * an attachment we know about and have not fetched, which is a better state to
 * be in than not knowing it existed.
 */
class TicketEmailAttachment extends Model
{
    protected $table = 'support_ticket_email_attachments';

    protected $fillable = [
        'support_ticket_email_id', 'graph_attachment_id',
        'name', 'mime_type', 'size_bytes', 'storage_path',
    ];

    protected $casts = ['size_bytes' => 'integer'];

    public function email(): BelongsTo
    {
        return $this->belongsTo(TicketEmail::class, 'support_ticket_email_id');
    }

    public function isDownloaded(): bool
    {
        return filled($this->storage_path);
    }
}
