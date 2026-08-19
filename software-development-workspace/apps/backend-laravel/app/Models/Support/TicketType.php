<?php

namespace App\Models\Support;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * Incident, Service Request, Change Request and the rest.
 *
 * Ported from the old module's seven types because they are the words the team
 * already uses. Types will grow their own workflows later; for now they
 * classify, which is what they did before.
 */
class TicketType extends Model
{
    use BelongsToOrganization;

    protected $table = 'support_ticket_types';

    protected $fillable = [
        'organization_id', 'name', 'slug', 'description',
        'is_default', 'position', 'is_active',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active'  => 'boolean',
        'position'   => 'integer',
    ];

    protected $attributes = ['is_default' => false, 'is_active' => true];
}
