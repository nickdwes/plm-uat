<?php

namespace App\Models\Recruitment;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\URL;

class Candidate extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'candidates';

    protected $fillable = [
        'organization_id',
        'first_name',
        'middle_name',
        'last_name',
        'email',
        'phone',
        'resume_path',
        'social_links',
        'keywords',
        'source',
        'notes',
        'is_archived',
        'archived_at',
    ];

    protected $casts = [
        'social_links' => 'array',
        'keywords'     => 'array',
        'is_archived'  => 'boolean',
        'archived_at'  => 'datetime',
    ];

    public function getFullNameAttribute(): string
    {
        return collect([$this->first_name, $this->middle_name, $this->last_name])
            ->filter()
            ->join(' ');
    }

    /**
     * A time-limited link to this candidate's CV, or null when none is on file.
     *
     * Callers choose the lifetime because the two audiences differ: someone
     * looking at the candidate in the app needs minutes, while an external
     * panellist working from an invitation email needs the link to still work
     * when they sit down to interview.
     *
     * Anyone holding the link can read the CV until it expires, so it must only
     * be handed to a caller already entitled to see the candidate.
     */
    public function signedResumeUrl(int $minutes = 30): ?string
    {
        if (! $this->resume_path) {
            return null;
        }

        return URL::temporarySignedRoute(
            'candidates.resume.signed',
            now()->addMinutes($minutes),
            ['candidate' => $this->id],
        );
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class, 'candidate_id');
    }
}
