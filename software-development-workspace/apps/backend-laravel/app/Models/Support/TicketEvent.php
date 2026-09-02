<?php

namespace App\Models\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every hand-off, in order.
 *
 * "Who sent this to me, and why?" cannot be answered from a column that holds
 * only the current value — which is what assignment was before this table.
 */
class TicketEvent extends Model
{
    protected $table = 'support_ticket_events';

    public const ASSIGNED     = 'assigned';
    public const REASSIGNED   = 'reassigned';
    public const QUEUED       = 'queued';
    public const CLASSIFIED   = 'classified';
    public const ESCALATED    = 'escalated';
    public const DEESCALATED  = 'de-escalated';
    public const TRIAGED      = 'triaged';

    /*
     * The status trail.
     *
     * A ticket's own columns say where it is now, and reopening one clears its
     * resolved_at — correctly, because a resolution date on a ticket that is
     * open again is a false fact. But it meant last month's resolved count
     * changed whenever somebody reopened an old ticket, so a report run twice
     * gave two answers. These rows are written once and never revised, and the
     * resolution figures are counted from them for that reason.
     */
    public const STATUS   = 'status';
    public const RESOLVED = 'resolved';
    public const REOPENED = 'reopened';

    /*
     * Whose move it is. Set automatically by which side sent the last public
     * message, or by hand when that guess is wrong — a reply that says
     * "still looking into it" is not a handoff to the customer.
     */
    public const OWNER_CHANGED = 'owner_changed';

    protected $fillable = [
        'support_ticket_id', 'event', 'field', 'from_value', 'to_value',
        'reason', 'actor_id', 'automatic', 'occurred_at',
    ];

    protected $casts = ['automatic' => 'boolean', 'occurred_at' => 'datetime'];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'support_ticket_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
