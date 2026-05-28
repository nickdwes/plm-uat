<?php

namespace App\Models\Benefits;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BenefitUsageLog extends Model
{
    protected $table = 'benefit_usage_logs';

    protected $fillable = [
        'employee_id', 'benefit_definition_id', 'usage_date', 'amount_used', 'days_used', 'notes',
    ];

    protected $casts = [
        'amount_used' => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function benefit(): BelongsTo
    {
        return $this->belongsTo(BenefitDefinition::class, 'benefit_definition_id');
    }
}
