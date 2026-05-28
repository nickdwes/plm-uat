<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeDependent extends Model
{
    use SoftDeletes;

    protected $table = 'employee_dependents';

    protected $fillable = [
        'employee_id',
        'first_name',
        'last_name',
        'middle_name',
        'relationship',
        'date_of_birth',
        'gender',
        'national_id',
        'passport_number',
        'school_institution',
        'disability',
        'notes',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
    ];

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
