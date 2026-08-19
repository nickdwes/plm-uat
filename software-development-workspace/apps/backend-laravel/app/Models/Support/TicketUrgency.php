<?php

namespace App\Models\Support;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** How fast the customer needs it. One is the most severe. */
class TicketUrgency extends Model
{
    use BelongsToOrganization;

    protected $table = 'support_ticket_urgencies';

    protected $fillable = ['organization_id', 'name', 'slug', 'level', 'is_default'];

    protected $casts = ['level' => 'integer', 'is_default' => 'boolean'];

    protected $attributes = ['is_default' => false];
}
