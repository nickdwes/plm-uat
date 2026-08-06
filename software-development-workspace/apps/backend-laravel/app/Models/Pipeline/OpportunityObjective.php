<?php

namespace App\Models\Pipeline;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A commitment to land part of the deal in a named window.
 *
 * The legacy held one committed quarter on the opportunity, so a deal
 * delivered in phases could only be committed once. "Phase 1, Q1–Q3" is how
 * the commitment is actually made, which takes rows.
 */
class OpportunityObjective extends Model
{
    use BelongsToOrganization;

    protected $table = 'opportunity_objectives';

    protected $fillable = [
        'organization_id', 'opportunity_id', 'name',
        'expected_start_date', 'expected_end_date', 'created_by',
    ];

    protected $casts = [
        'expected_start_date' => 'date',
        'expected_end_date'   => 'date',
    ];

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    /** "Q1 – Q3", as the screen shows it under the objective name. */
    public function getQuarterSpanAttribute(): ?string
    {
        if (! $this->expected_start_date || ! $this->expected_end_date) {
            return null;
        }

        $from = 'Q' . (int) ceil($this->expected_start_date->month / 3);
        $to   = 'Q' . (int) ceil($this->expected_end_date->month / 3);

        return $from === $to && $this->expected_start_date->year === $this->expected_end_date->year
            ? $from
            : "{$from} – {$to}";
    }
}
