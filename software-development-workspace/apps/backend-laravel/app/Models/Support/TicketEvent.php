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
