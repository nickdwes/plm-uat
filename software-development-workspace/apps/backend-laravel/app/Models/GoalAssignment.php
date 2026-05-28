<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoalAssignment extends Model
{
    protected $fillable = ['goal_id', 'employee_id'];

    public function goal(): BelongsTo     { return $this->belongsTo(Goal::class); }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
}
