<?php

namespace App\Models\Benefits;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BenefitApproval extends Model
{
    protected $table = 'benefit_approvals';

    protected $fillable = [
        'employee_id', 'benefit_definition_id', 'requested_date', 'end_date',
        'reason', 'days_requested', 'supervisor_id', 'status', 'action_date', 'action_notes',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function benefit(): BelongsTo
    {
        return $this->belongsTo(BenefitDefinition::class, 'benefit_definition_id');
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisor_id');
    }
}
