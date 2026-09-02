<?php

namespace App\Models\Project;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One party to be trained under a training fulfilment — internal staff or the
 * customer's people, each with their own travel logistics.
 */
class ProjectTrainee extends Model
{
    use BelongsToOrganization;

    protected $table = 'project_trainees';

    public const PARTIES = ['internal', 'customer', 'partner'];

    protected $fillable = [
        'organization_id', 'project_training_fulfillment_id', 'party', 'employee_id',
        'name', 'email', 'designation', 'needs_travel', 'travel_location', 'travel_cost', 'notes',
    ];

    protected $casts = [
        'needs_travel' => 'boolean',
        'travel_cost'  => 'decimal:2',
    ];

    public function fulfillment(): BelongsTo
    {
        return $this->belongsTo(ProjectTrainingFulfillment::class, 'project_training_fulfillment_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
