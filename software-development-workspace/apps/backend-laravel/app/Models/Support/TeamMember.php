<?php

namespace App\Models\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamMember extends Model
{
    protected $table = 'support_team_members';

    protected $fillable = ['support_team_id', 'user_id', 'is_lead', 'accepts_assignment'];

    protected $casts = ['is_lead' => 'boolean', 'accepts_assignment' => 'boolean'];

    protected $attributes = ['is_lead' => false, 'accepts_assignment' => true];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'support_team_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
