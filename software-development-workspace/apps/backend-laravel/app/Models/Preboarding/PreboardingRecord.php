<?php

namespace App\Models\Preboarding;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PreboardingRecord extends Model
{
    use SoftDeletes;

    protected $table = 'preboarding_records';

    protected $fillable = [
        'organization_id', 'candidate_id', 'employee_id',
        'first_name', 'last_name', 'email', 'phone',
        'expected_start_date', 'position', 'employee_type',
        'status', 'token', 'token_expires_at', 'token_used_at',
        'link_expiry_days', 'link_resend_count',
        'medical_cover_eligible', 'form_config',
        'welcome_sent_at', 'announcement_sent_at',
        'initiated_by', 'notes',
    ];

    protected $casts = [
        'expected_start_date'    => 'date',
        'welcome_sent_at'        => 'datetime',
        'announcement_sent_at'   => 'datetime',
        'token_expires_at'       => 'datetime',
        'token_used_at'          => 'datetime',
        'medical_cover_eligible' => 'boolean',
        'form_config'            => 'array',
    ];

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    public function sections(): HasMany
    {
        return $this->hasMany(PreboardingSection::class, 'preboarding_id');
    }

    public function references(): HasMany
    {
        return $this->hasMany(PreboardingReference::class, 'preboarding_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(PreboardingAttachment::class, 'preboarding_id');
    }

    /** Compute overall progress: percentage of non-N/A sections approved. */
    public function progressPercent(): int
    {
        $sections = $this->sections;
        $active   = $sections->where('status', '!=', 'not_applicable');
        if ($active->isEmpty()) return 0;
        $approved = $active->where('status', 'approved')->count();
        return (int) round($approved / $active->count() * 100);
    }
}
