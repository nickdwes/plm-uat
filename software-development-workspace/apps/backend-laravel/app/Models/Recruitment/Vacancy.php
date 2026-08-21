<?php

namespace App\Models\Recruitment;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Organization;
use App\Models\Department;
use App\Models\Designation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Vacancy extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'vacancies';

    protected $fillable = [
        'uuid',
        'organization_id',
        'template_id',
        'workflow_id',
        'department_id',
        'designation_id',
        'hiring_manager_id',
        'name',
        'job_title',
        'location',
        'open_positions',
        'status',
        'application_form_schema',
        'required_skills',
        'description',
        'closing_date',
    ];

    protected $casts = [
        'application_form_schema' => 'array',
        'required_skills' => 'array',
        'open_positions'          => 'integer',
        'closing_date'            => 'date',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(VacancyTemplate::class, 'template_id');
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

    public function hiringManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hiring_manager_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class, 'vacancy_id');
    }
}
