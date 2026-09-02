<?php

namespace App\Models\Support;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Teams call scheduled against a ticket.
 */
class TicketMeeting extends Model
{
    use BelongsToOrganization;

    protected $table = 'support_ticket_meetings';

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'organization_id', 'task_id', 'support_ticket_id',
        'subject', 'scheduled_at', 'duration_minutes', 'attendees',
        'graph_event_id', 'join_url', 'recording_url', 'transcript',
        'status', 'cancelled_at', 'created_by',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'attendees'    => 'array',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'support_ticket_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Task\Task::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
