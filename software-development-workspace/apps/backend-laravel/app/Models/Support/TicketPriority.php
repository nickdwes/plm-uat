<?php

namespace App\Models\Support;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * What gets worked on first.
 *
 * Derived from impact and urgency rather than typed in, so two agents facing
 * the same facts reach the same answer. See PriorityMatrix.
 */
class TicketPriority extends Model
{
    use BelongsToOrganization;

    protected $table = 'support_ticket_priorities';

    protected $fillable = ['organization_id', 'name', 'slug', 'level', 'colour', 'is_default'];

    protected $casts = ['level' => 'integer', 'is_default' => 'boolean'];

    protected $attributes = ['is_default' => false];
}
