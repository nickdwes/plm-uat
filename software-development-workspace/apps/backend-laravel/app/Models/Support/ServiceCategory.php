<?php

namespace App\Models\Support;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\OrgPractice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which discipline owns the work.
 *
 * A separate axis from the ticket type. "Incident" says what kind of thing
 * happened; "Network" says who fixes it. Collapsed into one list, a network
 * incident has to be filed as one or the other and both the routing and the
 * reporting lose.
 */
class ServiceCategory extends Model
{
    use BelongsToOrganization;

    protected $table = 'support_service_categories';

    protected $fillable = [
        'organization_id', 'name', 'slug', 'org_practice_id', 'default_team_id',
        'is_technical', 'position', 'is_active',
    ];

    protected $casts = [
        'is_technical' => 'boolean',
        'is_active'    => 'boolean',
        'position'     => 'integer',
    ];

    protected $attributes = ['is_technical' => true, 'is_active' => true];

    public function practice(): BelongsTo
    {
        return $this->belongsTo(OrgPractice::class, 'org_practice_id');
    }

    /** The queue that handles this discipline, where one is named directly. */
    public function defaultTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'default_team_id');
    }
}
