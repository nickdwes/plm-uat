<?php

namespace App\Models\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The numbers, per priority, in working minutes.
 *
 * Minutes rather than hours throughout. Hours read more naturally and would
 * have to be converted at every comparison, which is exactly where the old code
 * went wrong.
 */
class SlaTarget extends Model
{
    protected $table = 'support_sla_targets';

    protected $fillable = [
        'support_sla_id', 'priority_id', 'response_minutes', 'resolution_minutes',
        'hod_escalation_minutes', 'cto_escalation_minutes', 'ceo_escalation_minutes',
    ];

    protected $casts = [
        'response_minutes'       => 'integer',
        'resolution_minutes'     => 'integer',
        'hod_escalation_minutes' => 'integer',
        'cto_escalation_minutes' => 'integer',
        'ceo_escalation_minutes' => 'integer',
    ];

    public function sla(): BelongsTo
    {
        return $this->belongsTo(Sla::class, 'support_sla_id');
    }

    public function priority(): BelongsTo
    {
        return $this->belongsTo(TicketPriority::class, 'priority_id');
    }
}
