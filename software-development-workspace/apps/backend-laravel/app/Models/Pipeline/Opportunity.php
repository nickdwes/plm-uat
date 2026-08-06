<?php

namespace App\Models\Pipeline;

use App\Models\Account\Account;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Employee;
use App\Support\Pipeline\OpportunityScore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A deal in progress.
 *
 * Whether it is open, won or lost is read from the phase rather than stored:
 * the legacy kept both and they disagreed — every one of its 130 rows said
 * status 'Pending' while seventeen of them sat in the Project Kick-Off phase,
 * which is not pending by any reading.
 */
class Opportunity extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'opportunities';

    protected $fillable = [
        'organization_id', 'account_id', 'lead_id', 'reference', 'name', 'summary', 'description',
        'opportunity_type', 'priority', 'practice', 'amount', 'expected_close_date',
        'opportunity_phase_id', 'owner_id', 'presale_lead_id',
        'account_weight', 'knows_procurement', 'deal_registered', 'compliance_pressure',
        'oem_pressure', 'prior_deal_closed', 'alignment_with_customer',
        'alignment_with_oem_financials', 'alignment_with_oem_solution',
        'alignment_with_oem_support', 'budget_confirmed_by_customer', 'gap_analysis',
        'is_committed', 'committed_year', 'committed_quarter', 'discuss_weekly',
        'closed_at', 'created_by',

        // Fit, copied from the lead on conversion and editable afterwards.
        'fit_company', 'fit_budget', 'fit_solution', 'fit_timeline', 'fit_stakeholder',
        'budget_availability', 'stakeholder_engagement', 'timeline_estimate',
        'technical_readiness', 'decision_authority_access', 'qualified_at',

        'confirmed_budget', 'budget_confirmed_at', 'budget_confirmed_by',
    ];

    protected $casts = [
        'amount'              => 'decimal:2',
        'expected_close_date' => 'date',
        'closed_at'           => 'datetime',
        'is_committed'        => 'boolean',
        'discuss_weekly'      => 'boolean',
        'knows_procurement'             => 'boolean',
        'deal_registered'               => 'boolean',
        'compliance_pressure'           => 'boolean',
        'oem_pressure'                  => 'boolean',
        'prior_deal_closed'             => 'boolean',
        'alignment_with_customer'       => 'boolean',
        'alignment_with_oem_financials' => 'boolean',
        'alignment_with_oem_solution'   => 'boolean',
        'alignment_with_oem_support'    => 'boolean',
        'budget_confirmed_by_customer'  => 'boolean',
        'qualified_at'        => 'datetime',
        'budget_confirmed_at' => 'datetime',
        'confirmed_budget'    => 'decimal:2',
        'fit_company'     => 'integer',
        'fit_budget'      => 'integer',
        'fit_solution'    => 'integer',
        'fit_timeline'    => 'integer',
        'fit_stakeholder' => 'integer',
    ];

    public const TYPES   = ['new_business', 'renewal', 'upgrade'];
    public const WEIGHTS = ['category_1', 'category_2', 'category_3'];

    // ── Relationships ─────────────────────────────────────────────────────

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(OpportunityPhase::class, 'opportunity_phase_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'owner_id');
    }

    public function presaleLead(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'presale_lead_id');
    }

    public function assignees(): HasMany
    {
        return $this->hasMany(OpportunityAssignee::class);
    }

    // ── Pipeline ──────────────────────────────────────────────────────────

    public function stageProgress(): HasMany
    {
        return $this->hasMany(OpportunityStageProgress::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(OpportunityDocument::class)->latest('id');
    }

    public function estimates(): HasMany
    {
        return $this->hasMany(OpportunityEstimate::class)->latest('id');
    }

    public function objectives(): HasMany
    {
        return $this->hasMany(OpportunityObjective::class)->orderBy('expected_start_date');
    }

    public function bids(): HasMany
    {
        return $this->hasMany(OpportunityBid::class)->latest('id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(OpportunityActivity::class)->orderByDesc('occurred_at');
    }

    public function researches(): HasMany
    {
        return $this->hasMany(Research::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(\App\Models\Project\Project::class);
    }

    /** The contract a won deal produced. */
    public function contracts(): HasMany
    {
        return $this->hasMany(\App\Models\Account\AccountContract::class);
    }

    // ── Derived ───────────────────────────────────────────────────────────

    /** Won, lost, or still running — always the phase's answer. */
    public function getOutcomeAttribute(): ?string
    {
        return $this->phase?->outcome;
    }

    public function getIsOpenAttribute(): bool
    {
        return $this->outcome === null;
    }

    public function getScoreAttribute(): int
    {
        return OpportunityScore::for($this);
    }

    public function getScoreBandAttribute(): string
    {
        return OpportunityScore::band($this->score);
    }

    /**
     * Whether the budget confirmation currently stands.
     *
     * Read from the timestamp rather than a flag, so revoking one cannot leave
     * a boolean saying otherwise. The amount survives a revocation — withdrawn
     * is not the same as never quoted.
     */
    public function getBudgetIsConfirmedAttribute(): bool
    {
        return $this->budget_confirmed_at !== null;
    }

    /** The fit assessment, which is a different question from the strength score. */
    public function getFitScoreAttribute(): ?int
    {
        return \App\Support\Pipeline\LeadQualification::score($this);
    }

    // ── Scopes ────────────────────────────────────────────────────────────

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereHas('phase', fn ($p) => $p->whereNull('outcome'))
            ->orWhereNull('opportunity_phase_id');
    }

    public function scopeWon(Builder $q): Builder
    {
        return $q->whereHas('phase', fn ($p) => $p->where('outcome', 'won'));
    }
}
