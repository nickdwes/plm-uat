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

class TrainingBudgetRequest extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'training_budget_requests';

    protected $fillable = [
        'uuid', 'organization_id', 'mode', 'training_item_id', 'employee_id',
        'payment_instructions', 'total_amount', 'currency', 'status',
        'manager_id', 'manager_decided_at', 'manager_notes',
        'finance_decided_by', 'finance_decided_at', 'finance_notes',
        'rejected_stage', 'rejection_reason', 'submitted_by',
    ];

    protected $casts = [
        'total_amount'       => 'decimal:2',
        'manager_decided_at' => 'datetime',
        'finance_decided_at' => 'datetime',
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

    // ── Relationships ────────────────────────────────────────────────────────

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function trainingItem(): BelongsTo
    {
        return $this->belongsTo(TrainingItem::class, 'training_item_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function financeDecidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finance_decided_by');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TrainingBudgetRequestItem::class, 'training_budget_request_id');
    }
}
