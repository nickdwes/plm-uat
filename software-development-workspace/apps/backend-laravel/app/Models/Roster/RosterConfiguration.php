<?php

namespace App\Models\Roster;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Organization;

class RosterConfiguration extends Model
{
    public const DETAIL_TOTAL_SHIFT_HOURS = 'total_shift_hours';
    public const DETAIL_ALLOCATED_HOURS   = 'allocated_hours';

    protected $fillable = [
        'organization_id',
        'primary_detail_shown',
        'swap_shifts_enabled',
        'manager_approval_required',
    ];

    protected $casts = [
        'swap_shifts_enabled'       => 'boolean',
        'manager_approval_required' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
