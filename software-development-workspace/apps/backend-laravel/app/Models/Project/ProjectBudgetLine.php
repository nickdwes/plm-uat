<?php

namespace App\Models\Project;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A planned line of a project's budget, in a single currency. Planned money —
 * kept distinct from expenses (actual spend) so budget-vs-actual is honest.
 */
class ProjectBudgetLine extends Model
{
    use BelongsToOrganization;

    protected $table = 'project_budget_lines';

    public const SOURCE_MANUAL      = 'manual';
    public const SOURCE_TRAVEL      = 'travel';
    public const SOURCE_PROCUREMENT = 'procurement';

    protected $fillable = [
        'organization_id', 'project_id',
        'label', 'category', 'source', 'source_ref', 'currency', 'amount', 'note', 'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
