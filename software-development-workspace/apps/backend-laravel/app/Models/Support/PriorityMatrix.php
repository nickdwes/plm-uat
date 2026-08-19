<?php

namespace App\Models\Support;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * impact × urgency → priority.
 *
 * The old module captured both inputs and combined them nowhere, so priority
 * was whatever the agent felt that morning. One lookup table, configured once,
 * makes it a policy rather than a mood.
 */
class PriorityMatrix extends Model
{
    use BelongsToOrganization;

    protected $table = 'support_priority_matrix';

    protected $fillable = ['organization_id', 'impact_id', 'urgency_id', 'priority_id'];

    public function impact(): BelongsTo
    {
        return $this->belongsTo(TicketImpact::class, 'impact_id');
    }

    public function urgency(): BelongsTo
    {
        return $this->belongsTo(TicketUrgency::class, 'urgency_id');
    }

    public function priority(): BelongsTo
    {
        return $this->belongsTo(TicketPriority::class, 'priority_id');
    }

    /**
     * The priority for a pair, or null when either input is missing.
     *
     * Null rather than a guess: a ticket that arrived by email has neither
     * impact nor urgency until somebody triages it, and inventing "Medium"
     * makes an untriaged ticket look triaged.
     */
    public static function resolve(int $organizationId, ?int $impactId, ?int $urgencyId): ?TicketPriority
    {
        if ($impactId === null || $urgencyId === null) {
            return null;
        }

        $row = static::withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->where('impact_id', $impactId)
            ->where('urgency_id', $urgencyId)
            ->first();

        return $row?->priority;
    }
}
