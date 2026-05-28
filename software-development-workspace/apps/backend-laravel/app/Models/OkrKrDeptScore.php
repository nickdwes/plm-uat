<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OkrKrDeptScore extends Model
{
    protected $table = 'okr_kr_dept_scores';

    protected $fillable = [
        'key_result_id', 'department_id', 'score', 'weight',
    ];

    protected $casts = [
        'score'  => 'decimal:2',
        'weight' => 'decimal:2',
    ];

    public function keyResult(): BelongsTo
    {
        return $this->belongsTo(OkrKeyResult::class, 'key_result_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
