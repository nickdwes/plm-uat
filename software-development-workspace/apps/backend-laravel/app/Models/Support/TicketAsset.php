<?php

namespace App\Models\Support;

use App\Models\Asset\Asset;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A piece of kit a ticket was about. */
class TicketAsset extends Model
{
    protected $table = 'support_ticket_assets';

    /** The thing that was broken, as against the things it took down with it. */
    public const PRIMARY  = 'primary';
    public const AFFECTED = 'affected';

    protected $fillable = ['support_ticket_id', 'asset_id', 'relationship', 'linked_by'];

    protected $attributes = ['relationship' => self::AFFECTED];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'support_ticket_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
