<?php

namespace App\Models\Support;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A rule that does the obvious thing so nobody has to.
 *
 * Written, then armed. A rule that closes tickets should be readable, arguable
 * and rehearsed before it is allowed to touch anything, which is what the dry
 * run is for — it records what it would have done without doing it.
 */
class Automation extends Model
{
    use BelongsToOrganization;

    protected $table = 'support_automations';

    public const ON_CREATED   = 'created';
    public const ON_UPDATED   = 'updated';
    public const ON_SCHEDULE  = 'scheduled';

    protected $fillable = [
        'organization_id', 'name', 'description', 'trigger',
        'conditions', 'actions', 'position', 'is_active', 'is_dry_run',
        'once_per_ticket', 'cooldown_hours', 'fired_count', 'last_fired_at', 'created_by',
    ];

    protected $casts = [
        'conditions'      => 'array',
        'actions'         => 'array',
        'is_active'       => 'boolean',
        'is_dry_run'      => 'boolean',
        'once_per_ticket' => 'boolean',
        'position'        => 'integer',
        'cooldown_hours'  => 'integer',
        'fired_count'     => 'integer',
        'last_fired_at'   => 'datetime',
    ];

    protected $attributes = [
        'is_active'       => false,
        'is_dry_run'      => true,
        'once_per_ticket' => true,
        'position'        => 0,
    ];

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class, 'support_automation_id');
    }

    /**
     * Whether this rule may act on this ticket now.
     *
     * Two separate guards. `once_per_ticket` stops a rule doing its job twice
     * to the same ticket at all; the cooldown stops a scheduled rule chasing
     * the same customer every fifteen minutes, which is harassment rather than
     * a follow-up.
     */
    public function mayActOn(Ticket $ticket): bool
    {
        $last = $this->runs()
            ->where('support_ticket_id', $ticket->id)
            ->where('was_dry_run', false)
            ->orderByDesc('fired_at')
            ->first();

        if (! $last) {
            return true;
        }

        if ($this->once_per_ticket) {
            return false;
        }

        return $this->cooldown_hours === null
            || $last->fired_at->addHours($this->cooldown_hours)->isPast();
    }
}
