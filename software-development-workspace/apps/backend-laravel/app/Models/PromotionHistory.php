<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromotionHistory extends Model
{
    protected $table = 'promotion_history';

    protected $fillable = [
        'employee_id', 'from_designation_id', 'to_designation_id',
        'from_job_grade_id', 'to_job_grade_id',
        'effective_date', 'notes', 'recorded_by',
    ];

    protected $casts = ['effective_date' => 'date'];

    public function employee(): BelongsTo          { return $this->belongsTo(Employee::class); }
    public function fromDesignation(): BelongsTo   { return $this->belongsTo(Designation::class, 'from_designation_id'); }
    public function toDesignation(): BelongsTo     { return $this->belongsTo(Designation::class, 'to_designation_id'); }
    public function fromJobGrade(): BelongsTo      { return $this->belongsTo(JobGrade::class, 'from_job_grade_id'); }
    public function toJobGrade(): BelongsTo        { return $this->belongsTo(JobGrade::class, 'to_job_grade_id'); }
}
