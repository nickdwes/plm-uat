<?php

namespace App\Models\Preboarding;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PreboardingSection extends Model
{
    protected $table = 'preboarding_sections';

    protected $fillable = [
        'preboarding_id', 'section',
        'status', 'data', 'hr_notes',
        'reviewed_by', 'reviewed_at', 'submitted_at',
    ];

    protected $casts = [
        'data'        => 'array',
        'reviewed_at' => 'datetime',
        'submitted_at'=> 'datetime',
    ];

    public function record(): BelongsTo
    {
        return $this->belongsTo(PreboardingRecord::class, 'preboarding_id');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['pending', 'submitted']);
    }
}
