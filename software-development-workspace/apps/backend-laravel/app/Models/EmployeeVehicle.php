<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeVehicle extends Model
{
    use SoftDeletes;

    protected $table = 'employee_vehicles';

    protected $fillable = [
        'employee_id',
        'registration_number',
        'make',
        'model',
        'color',
        'year',
        'parking_slot',
        'notes',
    ];

    protected $casts = [
        'year' => 'integer',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
