<?php

namespace App\Models\Project;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An approvable travel request generated from a travel cycle.
 */
class ProjectTravelRequest extends Model
{
    use BelongsToOrganization;

    protected $table = 'project_travel_requests';

    public const APPROVAL_STATUSES = ['pending', 'approved', 'rejected'];

    protected $fillable = [
        'organization_id', 'project_id', 'reference', 'cycle_key',
        'purpose', 'location', 'start_date', 'end_date', 'travel_days', 'mode', 'accommodation',
        'estimated_cost', 'currency', 'tasks', 'personnel',
        'approval_status', 'decided_by', 'decided_at', 'notes', 'created_by',
    ];

    protected $casts = [
        'start_date'     => 'date',
        'end_date'       => 'date',
        'accommodation'  => 'boolean',
        'estimated_cost' => 'decimal:2',
        'travel_days'    => 'integer',
        'tasks'          => 'array',
        'personnel'      => 'array',
        'decided_at'     => 'datetime',
    ];

    protected $attributes = ['currency' => 'KES', 'approval_status' => 'pending', 'accommodation' => false];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'decided_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }
}
