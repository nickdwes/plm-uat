<?php

namespace App\Models\Recruitment;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Application extends Model
{
    use SoftDeletes;

    protected $table = 'applications';

    protected $fillable = [
        'vacancy_id',
        'candidate_id',
        'current_stage_id',
        'status',
        'form_data',
        'screening_score',
        'applied_at',
    ];

    protected $casts = [
        'form_data'       => 'array',
        'screening_score' => 'integer',
        'applied_at'      => 'datetime',
    ];

    public function vacancy(): BelongsTo
    {
        return $this->belongsTo(Vacancy::class, 'vacancy_id');
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class, 'candidate_id');
    }

    public function currentStage(): BelongsTo
    {
        return $this->belongsTo(WorkflowStage::class, 'current_stage_id');
    }

    public function stageHistory(): HasMany
    {
        return $this->hasMany(ApplicationStageHistory::class, 'application_id')->orderBy('moved_at');
    }
}
