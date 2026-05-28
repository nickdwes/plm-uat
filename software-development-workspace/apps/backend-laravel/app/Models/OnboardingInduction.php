<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnboardingInduction extends Model
{
    protected $fillable = ['employee_id', 'label', 'done', 'notes', 'sort_order'];

    protected $casts = ['done' => 'boolean'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
