<?php

namespace App\Models\Pipeline;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * Where a deal has got to.
 *
 * `outcome` is what makes Win and Lost different from the twelve phases that
 * precede them: it is the single place that decides whether an opportunity
 * counts as closed, so nothing else has to be kept in step with it.
 */
class OpportunityPhase extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'name', 'position', 'outcome', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'position' => 'integer'];

    protected $attributes = ['is_active' => true];

    public const OUTCOMES = ['won', 'lost'];
}
