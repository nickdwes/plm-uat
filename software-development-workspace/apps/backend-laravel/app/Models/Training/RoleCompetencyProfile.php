<?php

namespace App\Models\Training;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Department;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class RoleCompetencyProfile extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'training_role_profiles';

    protected $fillable = [
        'uuid', 'organization_id', 'job_title', 'department_id', 'description',
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

    // ── Relationships ──────────────────────────────────────────────────────────

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function competencies(): BelongsToMany
    {
        return $this->belongsToMany(
            CompetencyItem::class,
            'training_role_profile_competencies',
            'profile_id',
            'competency_item_id'
        )->withPivot('required_level');
    }
}
