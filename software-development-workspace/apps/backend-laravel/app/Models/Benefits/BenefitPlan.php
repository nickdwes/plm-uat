<?php

namespace App\Models\Benefits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BenefitPlan extends Model
{
    use SoftDeletes;

    protected $table = 'benefit_plans';

    protected $fillable = [
        'organization_id', 'name', 'description', 'eligibility_rules', 'is_active',
    ];

    protected $casts = [
        'eligibility_rules' => 'array',
        'is_active'         => 'boolean',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(BenefitPlanItem::class, 'plan_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeBenefitPlan::class, 'plan_id');
    }
}
