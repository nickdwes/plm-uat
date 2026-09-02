<?php

namespace App\Models\Pipeline;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A Teams meeting booked against a lead — the correspondence tab's calls. */
class LeadMeeting extends Model
{
    use BelongsToOrganization;

    protected $table = 'lead_meetings';

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'organization_id', 'lead_id', 'subject', 'scheduled_at', 'duration_minutes',
        'agenda', 'attendees', 'graph_event_id', 'join_url', 'recording_url',
        'transcript', 'status', 'cancelled_at', 'created_by',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'attendees'    => 'array',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
