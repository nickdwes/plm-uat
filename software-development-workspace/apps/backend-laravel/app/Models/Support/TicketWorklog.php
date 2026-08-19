<?php

namespace App\Models\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Time spent on a ticket.
 *
 * Recorded from the first release and deliberately carrying no rate, no
 * invoice and no billing state — whether support is billed against contracts
 * is still an open question. Time is easy to ignore if it is there and
 * impossible to reconstruct if it is not.
 */
class TicketWorklog extends Model
{
    protected $table = 'support_ticket_worklogs';

    protected $fillable = [
        'support_ticket_id', 'user_id', 'minutes', 'performed_on', 'note', 'under_contract',
    ];

    protected $casts = [
        'minutes'        => 'integer',
        'performed_on'   => 'date',
        'under_contract' => 'boolean',
    ];

    protected $attributes = ['under_contract' => true];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'support_ticket_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
