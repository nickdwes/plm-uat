<?php

namespace App\Models\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What happened to the clock, written as it happened.
 *
 * Never recomputed. A service review three months later is argued from this
 * table, and a figure that can be re-derived differently after the fact is a
 * figure both sides can dispute.
 */
class SlaEvent extends Model
{
    protected $table = 'support_sla_events';

    public const STARTED   = 'started';
    public const PAUSED    = 'paused';
    public const RESUMED   = 'resumed';
    public const RESPONDED = 'responded';
    public const RESOLVED  = 'resolved';
    public const BREACHED  = 'breached';
    public const ESCALATED = 'escalated';

    protected $fillable = [
        'support_ticket_id', 'event', 'clock', 'detail', 'working_minutes', 'occurred_at',
    ];

    protected $casts = ['occurred_at' => 'datetime', 'working_minutes' => 'integer'];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'support_ticket_id');
    }
}
