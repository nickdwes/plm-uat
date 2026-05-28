<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveType extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'days_per_year',
        'is_paid',
        'carry_forward',
        'max_carry_days',
        'requires_approval',
        'is_active',
        'counting_method',
        'working_days',
        'allow_half_day',
        'allow_scattered',
    ];

    protected $casts = [
        'is_paid'           => 'boolean',
        'carry_forward'     => 'boolean',
        'requires_approval' => 'boolean',
        'is_active'         => 'boolean',
        'allow_half_day'    => 'boolean',
        'allow_scattered'   => 'boolean',
        'working_days'      => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function balances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
