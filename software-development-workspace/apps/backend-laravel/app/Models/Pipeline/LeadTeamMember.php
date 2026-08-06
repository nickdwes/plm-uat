<?php

namespace App\Models\Pipeline;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Somebody researching a lead. */
class LeadTeamMember extends Model
{
    use BelongsToOrganization;

    protected $table = 'lead_team';

    protected $fillable = ['organization_id', 'lead_id', 'employee_id', 'role'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
