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
        'organization_id', 'account_id', 'lead_id', 'reference', 'name', 'description',
        'opportunity_type', 'practice', 'amount', 'expected_close_date',
        'opportunity_phase_id', 'owner_id', 'presale_lead_id',
        'account_weight', 'knows_procurement', 'deal_registered', 'compliance_pressure',
        'oem_pressure', 'prior_deal_closed', 'alignment_with_customer',
        'alignment_with_oem_financials', 'alignment_with_oem_solution',
        'alignment_with_oem_support', 'budget_confirmed_by_customer', 'gap_analysis',
        'is_committed', 'committed_year', 'committed_quarter', 'discuss_weekly',
        'closed_at', 'created_by',
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
