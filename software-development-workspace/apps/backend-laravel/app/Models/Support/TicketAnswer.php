<?php

namespace App\Models\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One answer from a request form.
 *
 * The label is copied from the form as it stood. Editing a question later must
 * not rewrite what a past requester was actually asked.
 */
class TicketAnswer extends Model
{
    protected $table = 'support_ticket_answers';

    protected $fillable = [
        'support_ticket_id', 'support_service_id',
        'field_key', 'label', 'value', 'position',
    ];

    protected $casts = ['position' => 'integer'];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'support_ticket_id');
    }
}
