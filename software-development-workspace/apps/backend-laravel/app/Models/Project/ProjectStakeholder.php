<?php

namespace App\Models\Project;

use App\Models\Account\AccountContact;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in the project's stakeholder register — a customer person, their
 * persona and influence, and what the project engages them for. Can link an
 * account contact or stand alone.
 */
class ProjectStakeholder extends Model
{
    use BelongsToOrganization;

    protected $table = 'project_stakeholders';

    public const LEVELS = ['high', 'medium', 'low'];

    protected $fillable = [
        'organization_id', 'project_id', 'account_contact_id',
        'name', 'title', 'email', 'phone', 'persona',
        'influence', 'interest', 'responsibility', 'position', 'created_by',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(AccountContact::class, 'account_contact_id');
    }
}
