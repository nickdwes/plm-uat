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

class VacancyTemplate extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'vacancy_templates';

    protected $fillable = [
        'organization_id',
        'workflow_id',
        'department_id',
        'designation_id',
        'name',
        'job_title',
        'location',
        'default_positions',
        'application_form_schema',
        'screening_rules',
        'is_active',
    ];

    protected $casts = [
        'application_form_schema' => 'array',
        'screening_rules'         => 'array',
        'is_active'               => 'boolean',
        'default_positions'       => 'integer',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(RecruitmentWorkflow::class, 'workflow_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    public function vacancies(): HasMany
    {
        return $this->hasMany(Vacancy::class, 'template_id');
    }
}
