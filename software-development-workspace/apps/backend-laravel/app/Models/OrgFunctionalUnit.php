<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrgFunctionalUnit extends Model
{
    use SoftDeletes, BelongsToOrganization;

    protected $table = 'org_functional_units';

    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'description',
        'lead_employee_id',
        'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Whoever runs this function. */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Employee::class, 'lead_employee_id');
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class, 'functional_unit_id');
    }
}
