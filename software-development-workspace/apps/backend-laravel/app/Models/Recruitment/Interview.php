<?php

namespace App\Models\Recruitment;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Interview extends Model
{
    use SoftDeletes;

    protected $table = 'interviews';

    protected $fillable = [
        'application_id',
        'vacancy_id',
        'organization_id',
        'title',
        'interview_type',
        'scheduled_at',
        'duration_minutes',
        'location',
        'agenda',
        'status',
        'overall_notes',
        'overall_score',
        'recommendation',
        'teams_event_id',
        'teams_join_url',
        'candidate_invite_sent',
        'candidate_invite_sent_at',
    ];

    protected $casts = [
        'scheduled_at'             => 'datetime',
        'overall_score'            => 'float',
        'duration_minutes'         => 'integer',
        'candidate_invite_sent'    => 'boolean',
        'candidate_invite_sent_at' => 'datetime',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'application_id');
    }

    public function vacancy(): BelongsTo
    {
        return $this->belongsTo(Vacancy::class, 'vacancy_id');
    }

    public function panelists(): HasMany
    {
        return $this->hasMany(InterviewPanelist::class, 'interview_id');
    }

    public function scores(): HasMany
    {
        return $this->hasMany(InterviewScore::class, 'interview_id');
    }
}
