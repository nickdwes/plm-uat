<?php

namespace App\Models\Training;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingBudgetRequestItem extends Model
{
    protected $table = 'training_budget_request_items';

    protected $fillable = [
        'training_budget_request_id', 'employee_id', 'training_item_id',
        'cost', 'achieve_by_date',
    ];

    protected $casts = [
        'cost'            => 'decimal:2',
        'achieve_by_date' => 'date',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(TrainingBudgetRequest::class, 'training_budget_request_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function trainingItem(): BelongsTo
    {
        return $this->belongsTo(TrainingItem::class, 'training_item_id');
    }
}
