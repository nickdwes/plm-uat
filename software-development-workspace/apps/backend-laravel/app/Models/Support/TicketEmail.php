<?php

namespace App\Models\Support;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One email, in or out.
 *
 * Kept whole alongside the thread message it produced. The thread is what an
 * agent reads; this is what it actually was — headers, every recipient, and the
 * attachments — and it is what answers "who else was copied on that?" six
 * months later.
 */
class TicketEmail extends Model
{
    use BelongsToOrganization;

    protected $table = 'support_ticket_emails';

    public const INBOUND  = 'inbound';
    public const OUTBOUND = 'outbound';

    protected $fillable = [
        'organization_id', 'support_mailbox_id', 'support_ticket_id', 'support_ticket_message_id',
        'direction', 'graph_message_id', 'internet_message_id', 'in_reply_to', 'references',
        'from_name', 'from_email', 'subject', 'body_preview', 'body_html', 'received_at',
        'quarantine_reason', 'released_at',
    ];

    protected $casts = [
        'received_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'support_ticket_id');
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class, 'support_mailbox_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(TicketEmailRecipient::class, 'support_ticket_email_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TicketEmailAttachment::class, 'support_ticket_email_id');
    }

    public function isQuarantined(): bool
    {
        return $this->quarantine_reason !== null && $this->released_at === null;
    }
}
