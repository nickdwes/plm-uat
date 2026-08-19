<?php

namespace App\Models\Support;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * How much of the business it touches.
 *
 * A separate question from urgency, and the one people conflate: a printer
 * nobody can use is urgent to one person and low impact to everybody else.
 */
class TicketImpact extends Model
{
    use BelongsToOrganization;

    protected $table = 'support_ticket_impacts';

    protected $fillable = ['organization_id', 'name', 'slug', 'level', 'is_default'];

    protected $casts = ['level' => 'integer', 'is_default' => 'boolean'];

    protected $attributes = ['is_default' => false];
}
