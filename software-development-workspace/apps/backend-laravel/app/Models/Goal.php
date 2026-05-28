<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Goal extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'parent_goal_id', 'created_by_id',
        'title', 'description', 'level', 'priority', 'status',
        'weight', 'due_date', 'completion', 'is_okr', 'in_library',
    ];

    protected $casts = [
        'due_date'   => 'date:Y-m-d',
        'is_okr'     => 'boolean',
        'in_library' => 'boolean',
        'completion' => 'float',
        'weight'     => 'float',
    ];

    public function organization(): BelongsTo  { return $this->belongsTo(Organization::class); }
    public function parent(): BelongsTo        { return $this->belongsTo(Goal::class, 'parent_goal_id'); }
    public function subGoals(): HasMany        { return $this->hasMany(Goal::class, 'parent_goal_id'); }
    public function createdBy(): BelongsTo     { return $this->belongsTo(Employee::class, 'created_by_id'); }
    public function assignments(): HasMany     { return $this->hasMany(GoalAssignment::class); }
    public function keyResults(): HasMany      { return $this->hasMany(GoalKeyResult::class)->orderBy('id'); }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'goal_assignments')->withTimestamps();
    }

    /** Auto-derive status from completion value */
    public static function statusFromCompletion(float $completion): string
    {
        if ($completion >= 100) return 'achieved';
        if ($completion > 0)   return 'in_progress';
        return 'pending';
    }
}
