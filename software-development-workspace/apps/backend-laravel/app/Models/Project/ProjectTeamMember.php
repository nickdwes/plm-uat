<?php

namespace App\Models\Project;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Somebody delivering a project. */
class ProjectTeamMember extends Model
{
    use BelongsToOrganization;

    protected $table = 'project_team';

    protected $fillable = ['organization_id', 'project_id', 'employee_id', 'role'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
