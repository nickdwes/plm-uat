<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrgPractice extends Model
{
    use SoftDeletes, BelongsToOrganization;

    protected $table = 'org_practices';

    protected $fillable = [
        'organization_id',
        'business_unit_id',
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

    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(OrgBusinessUnit::class, 'business_unit_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'lead_employee_id');
    }
}
