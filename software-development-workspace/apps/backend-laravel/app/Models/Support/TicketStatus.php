<?php

namespace App\Models\Support;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * A status, and — the part that matters — what it means.
 *
 * The old module had eighteen statuses and no way to ask whether any of them
 * meant the ticket was waiting on us. Every status here declares a state, and
 * the SLA clock reads the state rather than the name. Rename "With Supplier"
 * to anything you like; as long as it stays `pending`, the clock still stops.
 */
class TicketStatus extends Model
{
    use BelongsToOrganization;

    protected $table = 'support_ticket_statuses';

    /** With us. The clock runs. */
    public const STATE_OPEN = 'open';

    /** With the customer or a third party. The clock stops. */
    public const STATE_PENDING = 'pending';

    /** Fixed, awaiting confirmation or auto-close. */
    public const STATE_RESOLVED = 'resolved';

    public const STATE_CLOSED = 'closed';

    public const STATES = [
        self::STATE_OPEN     => 'Open — with us',
        self::STATE_PENDING  => 'Pending — waiting on somebody else',
        self::STATE_RESOLVED => 'Resolved',
        self::STATE_CLOSED   => 'Closed',
    ];

    protected $fillable = [
        'organization_id', 'name', 'slug', 'state', 'colour',
        'is_default', 'position', 'is_active',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active'  => 'boolean',
        'position'   => 'integer',
    ];

    protected $attributes = [
        'state'      => self::STATE_OPEN,
        'is_default' => false,
        'is_active'  => true,
    ];

    /** Whether work on this ticket is counted against a response target. */
    public function stopsTheClock(): bool
    {
        return in_array($this->state, [
            self::STATE_PENDING, self::STATE_RESOLVED, self::STATE_CLOSED,
        ], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->state, [self::STATE_RESOLVED, self::STATE_CLOSED], true);
    }
}
