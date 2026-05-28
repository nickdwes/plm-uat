<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OkrKeyResult extends Model
{
    protected $table = 'okr_key_results';

    protected $fillable = [
        'objective_id', 'title', 'measure', 'target_value',
        'achievement', 'score_percent', 'weight', 'sort_order',
    ];

    protected $casts = [
        'target_value'  => 'decimal:2',
        'achievement'   => 'decimal:2',
        'score_percent' => 'decimal:2',
        'weight'        => 'decimal:2',
        'sort_order'    => 'integer',
    ];

    public function objective(): BelongsTo
    {
        return $this->belongsTo(OkrObjective::class, 'objective_id');
    }

    public function deptScores(): HasMany
    {
        return $this->hasMany(OkrKrDeptScore::class, 'key_result_id');
    }
}
