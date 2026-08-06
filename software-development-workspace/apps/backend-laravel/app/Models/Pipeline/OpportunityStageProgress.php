<?php

namespace App\Models\Pipeline;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A stage this deal has finished. See the migration for why it is a row. */
class OpportunityStageProgress extends Model
{
    use BelongsToOrganization;

    protected $table = 'opportunity_stage_progress';

    protected $fillable = [
        'organization_id', 'opportunity_id', 'opportunity_phase_id',
        'completed_at', 'completed_by',
    ];

    protected $casts = ['completed_at' => 'datetime'];

    public function phase(): BelongsTo
    {
        return $this->belongsTo(OpportunityPhase::class, 'opportunity_phase_id');
    }
}
