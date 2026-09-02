<?php

namespace App\Models\Project;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Training\TrainingItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A training order activated into an enablement plan — a course, a cost per
 * person, and the register of trainees. The order points here as its
 * fulfilment record.
 */
class ProjectTrainingFulfillment extends Model
{
    use BelongsToOrganization;

    protected $table = 'project_training_fulfillments';

    public const MODES    = ['virtual', 'classroom', 'hybrid'];
    public const STATUSES = ['planned', 'booked', 'attended', 'completed', 'certified', 'closed'];

    protected $fillable = [
        'organization_id', 'project_id', 'order_id', 'training_item_id',
        'title', 'cost_per_person', 'currency', 'mode', 'status', 'notes', 'created_by',
    ];

    protected $casts = ['cost_per_person' => 'decimal:2'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ProjectOrder::class, 'order_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(TrainingItem::class, 'training_item_id');
    }

    public function trainees(): HasMany
    {
        return $this->hasMany(ProjectTrainee::class)->orderBy('id');
    }
}
