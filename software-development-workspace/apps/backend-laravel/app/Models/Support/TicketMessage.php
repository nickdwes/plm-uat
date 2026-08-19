<?php

namespace App\Models\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message on a ticket — a reply the customer sees, or a note they never do.
 *
 * The private half is the reason this table exists in this shape. The old
 * module had no internal channel, and its thirteen notes across 1,792 tickets
 * are what that costs: everything an agent worked out went to the customer or
 * went nowhere.
 */
class TicketMessage extends Model
{
    protected $table = 'support_ticket_messages';

    public const VISIBILITY_PUBLIC   = 'public';
    public const VISIBILITY_INTERNAL = 'internal';

    protected $fillable = [
        'support_ticket_id', 'visibility', 'body',
        'author_user_id', 'author_name', 'author_email',
        'source', 'is_from_agent',
    ];

    protected $casts = ['is_from_agent' => 'boolean'];

    protected $attributes = [
        'visibility'    => self::VISIBILITY_PUBLIC,
        'source'        => 'agent',
        'is_from_agent' => false,
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'support_ticket_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /** A first response is a public agent reply, and nothing else. */
    public function countsAsFirstResponse(): bool
    {
        return $this->is_from_agent && $this->visibility === self::VISIBILITY_PUBLIC;
    }
}
