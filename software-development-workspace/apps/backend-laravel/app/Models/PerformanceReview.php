<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerformanceReview extends Model
{
    protected $fillable = [
        'employee_id', 'reviewer_id', 'review_cycle', 'cycle_id',
        'review_date', 'rating', 'goals', 'comments', 'outcomes',
    ];

    protected $casts = ['review_date' => 'date', 'rating' => 'integer'];

    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function reviewer(): BelongsTo { return $this->belongsTo(Employee::class, 'reviewer_id'); }

    /**
     * The window this review covers.
     *
     * Null on every review written before cycles existed — those belong to a
     * period nobody recorded, and inventing one would put a made-up date range
     * behind a real appraisal.
     */
    public function cycle(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\PerformanceCycle::class, 'cycle_id');
    }
}
