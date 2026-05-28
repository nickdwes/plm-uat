<?php

namespace App\Models\Roster;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Organization;

class RosterShiftGroup extends Model
{
    protected $fillable = [
        'organization_id',
        'name',
        'description',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function shiftTypes(): HasMany
    {
        return $this->hasMany(RosterShiftType::class, 'shift_group_id');
    }
}
