<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeQualification extends Model
{
    protected $fillable = [
        'employee_id', 'title', 'institution', 'qualification_type',
        'field_of_study', 'year_obtained', 'certificate_number', 'notes',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
