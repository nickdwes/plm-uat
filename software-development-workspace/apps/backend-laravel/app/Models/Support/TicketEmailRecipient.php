<?php

namespace App\Models\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One address on one email. Modelled per recipient, as the old schema did. */
class TicketEmailRecipient extends Model
{
    protected $table = 'support_ticket_email_recipients';

    protected $fillable = ['support_ticket_email_id', 'kind', 'name', 'address'];

    public function email(): BelongsTo
    {
        return $this->belongsTo(TicketEmail::class, 'support_ticket_email_id');
    }
}
