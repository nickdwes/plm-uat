<?php

namespace App\Models\Training;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class EmployeeTrainingRecord extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'employee_training_records';

    protected $fillable = [
        'uuid', 'organization_id', 'employee_id', 'training_item_id',
        'assignment_type', 'status', 'progress_percent',
        'started_at', 'completed_at', 'due_date',
        'score', 'is_priority', 'priority_reason',
        'approval_request_id', 'assigned_by', 'created_by',
    ];

    protected $casts = [
        'progress_percent'    => 'integer',
        'score'               => 'decimal:2',
        'is_priority'         => 'boolean',
        'started_at'          => 'datetime',
        'completed_at'        => 'datetime',
        'due_date'            => 'date',
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

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function trainingItem(): BelongsTo
    {
        return $this->belongsTo(TrainingItem::class, 'training_item_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function progressUpdates(): HasMany
    {
        return $this->hasMany(ProgressUpdate::class, 'training_record_id');
    }

    public function dailyCheckIns(): HasMany
    {
        return $this->hasMany(DailyCheckIn::class, 'training_record_id');
    }

    public function evidenceFiles(): HasMany
    {
        return $this->hasMany(TrainingEvidenceFile::class, 'training_record_id');
    }
}
