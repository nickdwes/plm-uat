<?php

namespace App\Models\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A ticket a problem accounts for, or a change was raised from. */
class TicketLink extends Model
{
    protected $table = 'support_ticket_links';

    public const CAUSED_BY   = 'caused_by';
    public const RESOLVED_BY = 'resolved_by';
    public const RELATED     = 'related';

    protected $fillable = [
        'support_ticket_id', 'support_problem_id', 'support_change_id',
        'relationship', 'linked_by',
    ];

    protected $attributes = ['relationship' => self::RELATED];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'support_ticket_id');
    }

    public function problem(): BelongsTo
    {
        return $this->belongsTo(Problem::class, 'support_problem_id');
    }
}
