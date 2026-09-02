<?php

namespace App\Models\Project;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Actual money spent against a project, in a single currency — the counterpart
 * to a budget line. The sum per currency is what an over-run is measured from.
 */
class ProjectExpense extends Model
{
    use BelongsToOrganization;

    protected $table = 'project_expenses';

    /**
     * The vocabulary shared with budget lines so budget-vs-actual can be cut by
     * category on both sides. Free text is still accepted; these are the offered
     * set and the order the analytics reports in.
     */
    public const CATEGORIES = ['labour', 'travel', 'procurement', 'subcontract', 'materials', 'other'];

    protected $fillable = [
        'organization_id', 'project_id',
        'label', 'category', 'task_id', 'travel_request_id',
        'currency', 'amount', 'spent_on', 'note', 'created_by',
    ];

    protected $casts = [
        'amount'   => 'decimal:2',
        'spent_on' => 'date',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Task\Task::class, 'task_id');
    }

    public function travelRequest(): BelongsTo
    {
        return $this->belongsTo(ProjectTravelRequest::class, 'travel_request_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
