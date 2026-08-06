<?php

namespace App\Models\Pipeline;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Somebody working a deal, beyond the one who owns it. */
class OpportunityAssignee extends Model
{
    use BelongsToOrganization;

    protected $table = 'opportunity_assignees';

    protected $fillable = ['organization_id', 'opportunity_id', 'employee_id', 'role'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }
}
