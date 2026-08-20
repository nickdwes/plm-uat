<?php

namespace App\Models\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The clock on one ticket.
 *
 * The target is copied here rather than read through to the SLA on demand: a
 * contract renegotiated in March must not silently restate what January's
 * tickets were judged against.
 */
class TicketSla extends Model
{
    protected $table = 'support_ticket_slas';

    public const LEVEL_HOD = 'hod';
    public const LEVEL_CTO = 'cto';
    public const LEVEL_CEO = 'ceo';

    protected $fillable = [
        'support_ticket_id', 'support_sla_id', 'support_sla_target_id',
        'response_minutes', 'resolution_minutes',
        'response_due_at', 'resolution_due_at',
        'response_minutes_used', 'resolution_minutes_used', 'paused_minutes',
        'clock_started_at', 'paused_at',
        'response_met', 'resolution_met',
        'response_breached_at', 'resolution_breached_at',
        'response_settled_at', 'resolution_settled_at',
        'escalation_level', 'hod_escalated_at', 'cto_escalated_at', 'ceo_escalated_at',
    ];

    protected $casts = [
        'response_due_at'        => 'datetime',
        'resolution_due_at'      => 'datetime',
        'clock_started_at'       => 'datetime',
        'paused_at'              => 'datetime',
        'response_breached_at'   => 'datetime',
        'response_settled_at'    => 'datetime',
        'resolution_settled_at'  => 'datetime',
        'resolution_breached_at' => 'datetime',
        'hod_escalated_at'       => 'datetime',
        'cto_escalated_at'       => 'datetime',
        'ceo_escalated_at'       => 'datetime',
        'response_met'           => 'boolean',
        'resolution_met'         => 'boolean',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'support_ticket_id');
    }

    public function sla(): BelongsTo
    {
        return $this->belongsTo(Sla::class, 'support_sla_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(SlaTarget::class, 'support_sla_target_id');
    }

    public function isPaused(): bool
    {
        return $this->paused_at !== null;
    }
}
