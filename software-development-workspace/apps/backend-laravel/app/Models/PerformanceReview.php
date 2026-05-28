<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerformanceReview extends Model
{
    protected $fillable = [
        'employee_id', 'reviewer_id', 'review_cycle',
        'review_date', 'rating', 'goals', 'comments', 'outcomes',
    ];

    protected $casts = ['review_date' => 'date', 'rating' => 'integer'];

    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function reviewer(): BelongsTo { return $this->belongsTo(Employee::class, 'reviewer_id'); }
}
