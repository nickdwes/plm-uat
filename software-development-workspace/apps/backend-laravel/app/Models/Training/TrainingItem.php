<?php

namespace App\Models\Training;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Department;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class TrainingItem extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'training_items';

    protected $fillable = [
        'uuid', 'organization_id', 'title', 'description', 'type', 'delivery',
        'duration_hours', 'duration_minutes', 'is_long_course',
        'provider_name', 'provider_url', 'cost_currency', 'estimated_cost',
        'tags', 'is_active', 'is_featured', 'max_enrollments', 'thumbnail_url',
        'requires_approval', 'is_budgeted', 'budget_amount',
    ];

    protected $casts = [
        'tags'              => 'array',
        'is_active'         => 'boolean',
        'is_featured'       => 'boolean',
        'is_long_course'    => 'boolean',
        'requires_approval' => 'boolean',
        'is_budgeted'       => 'boolean',
        'estimated_cost'    => 'decimal:2',
        'budget_amount'     => 'decimal:2',
        'duration_hours'    => 'integer',
        'duration_minutes'  => 'integer',
        'max_enrollments'   => 'integer',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            // Auto-compute is_long_course
            $model->is_long_course = $model->duration_hours > 8;
        });
        static::updating(function (self $model) {
            $model->is_long_course = $model->duration_hours > 8;
        });
    }

    // ── Relationships ──────────────────────────────────────────────────────────

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function competencies(): BelongsToMany
    {
        return $this->belongsToMany(
            CompetencyItem::class,
            'training_item_competencies',
            'training_item_id',
            'competency_item_id'
        );
    }

    public function trainingRecords(): HasMany
    {
        return $this->hasMany(EmployeeTrainingRecord::class, 'training_item_id');
    }
}
