<?php

namespace App\Models\Preboarding;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PreboardingReference extends Model
{
    use SoftDeletes;

    protected $table = 'preboarding_references';

    protected $fillable = [
        'preboarding_id',
        'referee_name', 'referee_email', 'referee_company',
        'referee_job_title', 'relationship',
        'status', 'feedback',
        'invited_at', 'submitted_at',
    ];

    protected $casts = [
        'feedback'     => 'array',
        'invited_at'   => 'datetime',
        'submitted_at' => 'datetime',
    ];

    public function record(): BelongsTo
    {
        return $this->belongsTo(PreboardingRecord::class, 'preboarding_id');
    }
}
