<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class OnboardingAsset extends Model
{
    use SoftDeletes;

    protected $fillable = ['employee_id', 'name', 'serial_number', 'assigned_date'];

    protected $casts = ['assigned_date' => 'date'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
