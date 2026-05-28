<?php

namespace App\Models\Recruitment;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowStage extends Model
{
    protected $table = 'workflow_stages';

    protected $fillable = [
        'workflow_id',
        'name',
        'order_index',
        'stage_type',
        'coordinator_ids',
        'required_actions',
        'entry_criteria',
        'exit_criteria',
    ];

    protected $casts = [
        'coordinator_ids'  => 'array',
        'required_actions' => 'array',
        'entry_criteria'   => 'array',
        'exit_criteria'    => 'array',
        'order_index'      => 'integer',
    ];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(RecruitmentWorkflow::class, 'workflow_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class, 'current_stage_id');
    }
}
