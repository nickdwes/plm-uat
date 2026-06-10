<?php

namespace App\Models\Training;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Traits\HasUuids;

class TrainingAssignment extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'training_item_id',
        'employee_id',
        'assigned_by_user_id',
        'manager_id',
        'status',
        'rejection_reason',
        'approval_notes',
        'approved_at',
        'rejected_at',
        'withdrawn_at',
        'due_date',
        'assignment_type',
    ];

    protected $casts = [
        'due_date' => 'date',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'withdrawn_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $with = [];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function trainingItem()
    {
        return $this->belongsTo(TrainingItem::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function assignedByUser()
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function trainingRecord()
    {
        return $this->hasOne(EmployeeTrainingRecord::class, 'training_item_id', 'training_item_id')
            ->where('employee_id', $this->employee_id);
    }

    /**
     * Scope: Get pending assignments for a manager
     */
    public function scopePendingForManager($query, $managerId)
    {
        return $query->where('manager_id', $managerId)
            ->where('status', 'pending')
            ->orderBy('created_at', 'desc');
    }

    /**
     * Scope: Get by organization
     */
    public function scopeByOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Scope: Not yet actioned
     */
    public function scopeNotActioned($query)
    {
        return $query->whereIn('status', ['pending']);
    }
}
