<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingRecord extends Model
{
    protected $fillable = [
        'employee_id', 'course_name', 'provider',
        'completion_date', 'certification_no', 'notes',
    ];

    protected $casts = ['completion_date' => 'date'];

    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
}
