<?php

namespace App\Models\Pipeline;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResearchTeamMember extends Model
{
    use BelongsToOrganization;

    protected $table = 'research_team';

    protected $fillable = ['organization_id', 'research_id', 'employee_id', 'role'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
