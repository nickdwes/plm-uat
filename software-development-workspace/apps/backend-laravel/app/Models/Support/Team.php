<?php

namespace App\Models\Support;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A group work belongs to before it belongs to a person. */
class Team extends Model
{
    use BelongsToOrganization;

    protected $table = 'support_teams';

    protected $fillable = [
        'organization_id', 'name', 'slug', 'description',
        'org_practice_id', 'org_business_unit_id',
        'is_default', 'is_service_desk', 'is_vendor_queue', 'is_active',
    ];

    protected $casts = [
        'is_default'      => 'boolean',
        'is_service_desk' => 'boolean',
        'is_vendor_queue' => 'boolean',
        'is_active'       => 'boolean',
    ];

    /** The practice this queue belongs to, where it maps onto one. */
    public function practice(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\OrgPractice::class, 'org_practice_id');
    }

    public function businessUnit(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\OrgBusinessUnit::class, 'org_business_unit_id');
    }

    protected $attributes = ['is_default' => false, 'is_active' => true];

    public function members(): HasMany
    {
        return $this->hasMany(TeamMember::class, 'support_team_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'support_team_members', 'support_team_id', 'user_id')
            ->withPivot(['is_lead', 'accepts_assignment']);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'support_team_id');
    }

    /**
     * Who can actually be given something.
     *
     * Somebody on leave stays on the team and stops receiving work — removing
     * them would lose the fact that they belong to it, and they would come back
     * to a team they are no longer in.
     */
    public function assignableUserIds(): array
    {
        return $this->members()->where('accepts_assignment', true)->pluck('user_id')->all();
    }
}
