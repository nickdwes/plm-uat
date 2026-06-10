<?php

namespace App\Models\Training;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProgressUpdate extends Model
{
    protected $table = 'training_progress_updates';

    protected $fillable = [
        'training_record_id', 'employee_id', 'progress_percent', 'notes', 'recorded_at',
    ];

    protected $casts = [
        'progress_percent' => 'integer',
        'recorded_at'      => 'datetime',
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
