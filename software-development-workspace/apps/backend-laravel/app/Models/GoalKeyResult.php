<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoalKeyResult extends Model
{
    protected $fillable = [
        'goal_id', 'title', 'description',
        'use_metric', 'metric_format', 'metric_unit',
        'start_value', 'target_value', 'current_value',
        'completion', 'weight', 'due_date', 'status',
    ];

    protected $casts = [
        'due_date'      => 'date:Y-m-d',
        'use_metric'    => 'boolean',
        'completion'    => 'float',
        'weight'        => 'float',
        'start_value'   => 'float',
        'target_value'  => 'float',
        'current_value' => 'float',
    ];

    public function goal(): BelongsTo { return $this->belongsTo(Goal::class); }

    /**
     * OrangeHRM formula: Progress% = (current - start) / (target - start) × 100
     */
    public function computeCompletion(): float
    {
        if (!$this->use_metric) return (float) $this->completion;
        $range = (float) $this->target_value - (float) $this->start_value;
        if ($range == 0) return 0.0;
        $pct = (((float) $this->current_value - (float) $this->start_value) / $range) * 100;
        return max(0, round($pct, 2));
    }
}
