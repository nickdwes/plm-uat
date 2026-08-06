<?php

namespace App\Models\Project;

use App\Models\Account\Account;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Employee;
use App\Models\Pipeline\Opportunity;
use App\Models\Pipeline\OpportunityDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The work a won deal starts.
 *
 * Deliberately thin. A full project module — tasks, milestones, time, billing
 * — is its own piece of work; what a kickoff needs is the record that a
 * project exists, who leads it, when it is due, and the scope, SLA and bill of
 * materials it was agreed against. Building all of Projects to satisfy one
 * button would be the tail wagging the dog, and a thin table is far easier to
 * grow than a wrong one is to unpick.
 */
class Project extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'projects';

    protected $fillable = [
        'organization_id', 'account_id', 'opportunity_id', 'reference', 'name',
        'description', 'status', 'priority', 'deadline', 'project_lead_id', 'created_by',
    ];

    protected $casts = ['deadline' => 'date'];

    protected $attributes = ['status' => 'kickoff', 'priority' => 'medium'];

    public const STATUSES  = ['kickoff', 'in_flight', 'on_hold', 'delivered', 'cancelled'];
    public const PRIORITIES = ['low', 'medium', 'high'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function projectLead(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'project_lead_id');
    }

    /**
     * The kickoff paperwork, read from the opportunity's documents.
     *
     * Not copied onto the project: the scope of work the project starts
     * against is the one the deal was won on, and two copies would let them
     * drift apart at exactly the moment that matters.
     */
    public function kickoffDocuments(): HasMany
    {
        return $this->hasMany(OpportunityDocument::class, 'opportunity_id', 'opportunity_id')
            ->where('stage', 'kickoff');
    }
}
