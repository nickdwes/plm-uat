<?php

namespace App\Models\Training;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class CompetencyItem extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'training_competency_items';

    protected $fillable = [
        'uuid', 'organization_id', 'name', 'description', 'category', 'proficiency_levels',
    ];

    protected $casts = [
        'proficiency_levels' => 'array',
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

    public function trainingItems(): BelongsToMany
    {
        return $this->belongsToMany(
            TrainingItem::class,
            'training_item_competencies',
            'competency_item_id',
            'training_item_id'
        );
    }

    public function roleProfiles(): BelongsToMany
    {
        return $this->belongsToMany(
            RoleCompetencyProfile::class,
            'training_role_profile_competencies',
            'competency_item_id',
            'profile_id'
        )->withPivot('required_level');
    }
}
