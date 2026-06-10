<?php

namespace App\Models\Recruitment;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

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

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class, 'candidate_id');
    }
}
