<?php

namespace App\Models\Benefits;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeBenefitPlan extends Model
{
    protected $table = 'employee_benefit_plans';

    protected $fillable = [
        'employee_id', 'plan_id', 'effective_date', 'end_date', 'assigned_by', 'notes', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(BenefitPlan::class, 'plan_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_by');
    }
}
