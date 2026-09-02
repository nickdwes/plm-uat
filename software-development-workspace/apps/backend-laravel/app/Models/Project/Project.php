<?php

namespace App\Models\Project;

use App\Models\Account\Account;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Employee;
use App\Models\Pipeline\Opportunity;
use App\Models\Pipeline\OpportunityDocument;
use App\Models\Task\Task;
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
        'description', 'status', 'rag', 'priority', 'deadline', 'started_on', 'delivered_on',
        'financially_closed_at', 'financially_closed_by',
        'order_release_gate', 'contract_ready_override',
        'customer_lpo_number', 'customer_lpo_date', 'customer_lpo_value', 'customer_lpo_currency',
        'contract_value', 'project_lead_id', 'created_by',

        // The delivered split, kept apart from the estimate's planned one —
        // the gap between them is the point.
        'vendor_partner_id', 'delivery_days_own', 'delivery_days_oem', 'oem_ps_cost',

        // Charter — the governing facts the project is set up against.
        'scope', 'charter', 'pip', 'product', 'project_email',
        'execution_start', 'execution_end', 'charter_completion_date', 'contract_completion_date',
        'client_contact_id', 'client_sponsor_contact_id',
    ];

    protected $casts = [
        'deadline'       => 'date',
        'started_on'     => 'date',
        'delivered_on'   => 'date',
        'financially_closed_at' => 'datetime',
        'contract_ready_override' => 'boolean',
        'customer_lpo_date'  => 'date',
        'customer_lpo_value' => 'decimal:2',
        'contract_value' => 'decimal:2',
        'delivery_days_own' => 'decimal:1',
        'delivery_days_oem' => 'decimal:1',
        'oem_ps_cost'       => 'decimal:2',
        'execution_start'   => 'date',
        'execution_end'     => 'date',
        'charter_completion_date'  => 'date',
        'contract_completion_date' => 'date',
    ];

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

    /** The day-to-day client contact for the project. */
    public function clientContact(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Account\AccountContact::class, 'client_contact_id');
    }

    /** The client's project sponsor. */
    public function clientSponsor(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Account\AccountContact::class, 'client_sponsor_contact_id');
    }

    public function team(): HasMany
    {
        return $this->hasMany(ProjectTeamMember::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class)->orderBy('position');
    }

    /** Top-level tasks. Sub-tasks belong under their parent, not beside it. */
    public function rootTasks(): HasMany
    {
        return $this->tasks()->whereNull('parent_task_id');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(ProjectIssue::class)->latest('id');
    }

    public function phases(): HasMany
    {
        return $this->hasMany(ProjectPhase::class)->whereNull('parent_id')->orderBy('position');
    }

    public function allPhases(): HasMany
    {
        return $this->hasMany(ProjectPhase::class)->orderBy('position');
    }

    public function roles(): HasMany
    {
        return $this->hasMany(ProjectRole::class)->orderBy('position');
    }

    public function people(): HasMany
    {
        return $this->hasMany(ProjectPerson::class);
    }

    public function governanceRoles(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProjectGovernanceRole::class);
    }

    public function customerRoles(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProjectCustomerRole::class);
    }

    public function stakeholders(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProjectStakeholder::class);
    }

    public function professionalServices(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProjectProfessionalService::class);
    }

    public function subcontractors(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProjectSubcontractor::class);
    }

    public function travelRequests(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProjectTravelRequest::class);
    }

    public function vendorEngagements(): HasMany
    {
        return $this->hasMany(ProjectVendorEngagement::class)->latest('id');
    }

    public function boms(): HasMany
    {
        return $this->hasMany(ProjectBom::class)->latest('id');
    }

    public function financiallyClosedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'financially_closed_by');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(ProjectOrder::class)->latest('id');
    }

    public function trainingFulfillments(): HasMany
    {
        return $this->hasMany(ProjectTrainingFulfillment::class)->latest('id');
    }

    public function dependencies(): HasMany
    {
        return $this->hasMany(ProjectDependency::class)->latest('id');
    }

    public function risks(): HasMany
    {
        return $this->hasMany(ProjectRisk::class)->latest('id');
    }

    public function budgetLines(): HasMany
    {
        return $this->hasMany(ProjectBudgetLine::class)->orderBy('id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(ProjectExpense::class)->orderByDesc('spent_on')->orderByDesc('id');
    }

    public function vendors(): HasMany
    {
        return $this->hasMany(ProjectVendor::class)->orderBy('name');
    }

    public function weeklySnapshots(): HasMany
    {
        return $this->hasMany(ProjectWeeklySnapshot::class)->orderByDesc('week_start');
    }

    public function deliveryMilestones(): HasMany
    {
        return $this->hasMany(ProjectDeliveryMilestone::class)->orderBy('position')->orderBy('target_date');
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(ProjectMeeting::class)->orderByDesc('scheduled_at');
    }

    public function materialOrders(): HasMany
    {
        return $this->hasMany(ProjectMaterialOrder::class)->latest('id');
    }

    public function emails(): HasMany
    {
        return $this->hasMany(ProjectEmail::class)->orderByDesc('received_at');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ProjectComment::class)->whereNull('parent_id')->oldest('id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ProjectActivity::class)->orderByDesc('occurred_at');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ProjectAttachment::class);
    }

    /** Whose professional services took the OEM share of delivery. */
    public function vendorPartner(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Training\VendorPartner::class, 'vendor_partner_id');
    }

    /** The delivered OEM share, computed exactly as the estimate's is. */
    public function getOemShareAttribute(): ?int
    {
        return \App\Support\Pipeline\DeliverySplit::oemShare($this->delivery_days_own, $this->delivery_days_oem);
    }

    public function getTotalDeliveryDaysAttribute(): ?float
    {
        return \App\Support\Pipeline\DeliverySplit::totalDays($this->delivery_days_own, $this->delivery_days_oem);
    }

    /**
     * How far the delivered split moved from what the deal was scoped at.
     *
     * The number this whole thing exists to produce. Null until both exist —
     * a project with no estimate behind it has nothing to drift from.
     */
    public function getOemShareDriftAttribute(): ?int
    {
        $planned = $this->opportunity?->estimates?->first()?->oem_share;

        return \App\Support\Pipeline\DeliverySplit::drift($planned, $this->oem_share);
    }

    /** What the customer agreed to pay, and against which piece of work. */
    public function contractMilestones(): HasMany
    {
        return $this->hasMany(ProjectContractMilestone::class)->orderBy('due_on');
    }

    /**
     * How far along it is, as the cards show it.
     *
     * Counted across every task including sub-tasks, not just the top level.
     * Counting only roots would report a project as 0% while nine of its ten
     * sub-tasks are done, which is the opposite of what the number is for.
     *
     * Null when there are no tasks — a project nobody has planned is not 0%
     * complete, it is unplanned, and a progress bar at zero says the wrong
     * thing about it.
     */
    public function getProgressAttribute(): ?int
    {
        $tasks = $this->relationLoaded('tasks')
            ? $this->tasks
            : $this->tasks()->get(['id', 'status']);

        if ($tasks->isEmpty()) {
            return null;
        }

        $done = $tasks->where('status', Task::DONE)->count();

        return (int) round($done / $tasks->count() * 100);
    }

    /**
     * Whether it was delivered after its deadline.
     *
     * Derived rather than stored, and null until it is delivered — a project
     * still running is not late, it is running, and conflating the two is how
     * a delivery report ends up counting everything in flight as a failure.
     */
    public function getWasLateAttribute(): ?bool
    {
        if ($this->delivered_on === null || $this->deadline === null) {
            return null;
        }

        return $this->delivered_on->gt($this->deadline);
    }

    /** Running late right now — which is a different question from was_late. */
    public function getIsOverdueAttribute(): bool
    {
        return $this->delivered_on === null
            && $this->deadline !== null
            && $this->deadline->isPast()
            && ! in_array($this->status, ['delivered', 'cancelled'], true);
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
