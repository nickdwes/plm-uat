<?php

namespace App\Models\Pipeline;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What has happened on this deal. */
class OpportunityActivity extends Model
{
    use BelongsToOrganization;

    protected $table = 'opportunity_activities';

    protected $fillable = [
        'organization_id', 'opportunity_id', 'reference', 'kind', 'type',
        'body', 'occurred_at', 'employee_id', 'created_by',
    ];

    protected $casts = ['occurred_at' => 'datetime'];

    protected $attributes = ['kind' => 'manual', 'type' => 'note'];

    public const TYPES = ['note', 'call', 'meeting', 'email', 'demo', 'solution_design', 'site_visit'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
