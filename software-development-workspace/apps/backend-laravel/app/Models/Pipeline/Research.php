<?php

namespace App\Models\Pipeline;

use App\Models\Account\Account;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Work done to find out whether the thing can be built.
 *
 * The legacy's `mdl_projects_research` — twenty rows and its own menu entry.
 * All three links are optional and independent: research gets raised against a
 * customer before any lead exists, and against a lead before it becomes a deal.
 */
class Research extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'researches';

    protected $fillable = [
        'organization_id', 'account_id', 'lead_id', 'opportunity_id',
        'reference', 'name', 'description', 'findings', 'script', 'status', 'priority',
        'deadline', 'research_lead_id', 'created_by',
        'covers_concept', 'covers_architecture', 'covers_integration',
    ];

    protected $casts = [
        'deadline'            => 'date',
        'covers_concept'      => 'boolean',
        'covers_architecture' => 'boolean',
        'covers_integration'  => 'boolean',
    ];

    /**
     * What the work is asking, which decides who does it.
     *
     * From the legacy. "Can this be done at all", "how would it be built" and
     * "will it talk to what they already run" are answered by different people.
     */
    public const SCOPES = ['covers_concept', 'covers_architecture', 'covers_integration'];

    protected $attributes = ['status' => 'new', 'priority' => 'medium'];

    public const STATUSES  = ['new', 'in_progress', 'complete', 'abandoned'];
    public const PRIORITIES = ['low', 'medium', 'high'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function researchLead(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'research_lead_id');
    }

    public function team(): HasMany
    {
        return $this->hasMany(ResearchTeamMember::class);
    }
}
