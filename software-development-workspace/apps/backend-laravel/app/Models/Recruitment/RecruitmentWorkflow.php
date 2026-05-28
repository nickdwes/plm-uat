<?php

namespace App\Models\Recruitment;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Organization;
use App\Models\Department;
use App\Models\Designation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RecruitmentWorkflow extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'recruitment_workflows';

    protected $fillable = [
        'organization_id',
        'name',
        'description',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function stages(): HasMany
    {
        return $this->hasMany(WorkflowStage::class, 'workflow_id')->orderBy('order_index');
    }
}
