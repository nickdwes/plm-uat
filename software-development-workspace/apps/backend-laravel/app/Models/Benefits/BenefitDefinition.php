<?php

namespace App\Models\Benefits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BenefitDefinition extends Model
{
    use SoftDeletes;

    protected $table = 'benefit_definitions';

    protected $fillable = [
        'organization_id', 'name', 'benefit_type', 'eligibility_rules',
        'amount', 'amount_limit', 'payable_period', 'paid_with_salary',
        'requires_approval', 'requires_reason', 'max_days',
        'registration_required', 'mode', 'location_rates',
        'allowed_memberships', 'membership_rules', 'coverage_limit',
        'dependents_required', 'notes', 'is_active',
    ];

    protected $casts = [
        'eligibility_rules'     => 'array',
        'location_rates'        => 'array',
        'paid_with_salary'      => 'boolean',
        'requires_approval'     => 'boolean',
        'requires_reason'       => 'boolean',
        'registration_required' => 'boolean',
        'dependents_required'   => 'boolean',
        'is_active'             => 'boolean',
        'amount'                => 'decimal:2',
        'amount_limit'          => 'decimal:2',
        'coverage_limit'        => 'decimal:2',
    ];

    public function planItems(): HasMany
    {
        return $this->hasMany(BenefitPlanItem::class, 'benefit_definition_id');
    }

    public function usageLogs(): HasMany
    {
        return $this->hasMany(BenefitUsageLog::class, 'benefit_definition_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(BenefitApproval::class, 'benefit_definition_id');
    }
}
