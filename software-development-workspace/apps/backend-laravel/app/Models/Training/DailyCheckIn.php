<?php

namespace App\Models\Training;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyCheckIn extends Model
{
    protected $table = 'training_daily_check_ins';

    protected $fillable = [
        'training_record_id', 'employee_id', 'check_in_date', 'notes', 'hours_spent',
    ];

    protected $casts = [
        'check_in_date' => 'date',
        'hours_spent'   => 'integer',
    ];

    // ── Relationships ──────────────────────────────────────────────────────────

    public function trainingRecord(): BelongsTo
    {
        return $this->belongsTo(EmployeeTrainingRecord::class, 'training_record_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
