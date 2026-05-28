<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrgBusinessUnit extends Model
{
    use SoftDeletes, BelongsToOrganization;

    protected $table = 'org_business_units';

    protected $fillable = [
        'organization_id',
        'department_id',
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

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function practices(): HasMany
    {
        return $this->hasMany(OrgPractice::class, 'business_unit_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'lead_employee_id');
    }
}
