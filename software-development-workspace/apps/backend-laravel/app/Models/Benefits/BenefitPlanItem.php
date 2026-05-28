<?php

namespace App\Models\Benefits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BenefitPlanItem extends Model
{
    protected $table = 'benefit_plan_items';

    protected $fillable = [
        'plan_id', 'benefit_definition_id', 'amount_override', 'limit_override', 'max_days_override', 'notes',
    ];

    protected $casts = [
        'amount_override' => 'decimal:2',
        'limit_override'  => 'decimal:2',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(BenefitPlan::class, 'plan_id');
    }

    public function benefit(): BelongsTo
    {
        return $this->belongsTo(BenefitDefinition::class, 'benefit_definition_id');
    }
}
