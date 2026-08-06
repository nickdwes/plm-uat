<?php

namespace App\Models\Pipeline;

use App\Models\Account\Account;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Employee;
use App\Support\Pipeline\LeadQualification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Something that might become work.
 *
 * The legacy's status did two jobs: it held New/Cold/Lost, but also "Attached
 * to an opportunity" and "Attached to a Project" — 80 of 162 rows. That made
 * the status unusable as a status, because you could not ask "how many leads
 * are still open" without knowing which values secretly meant "converted".
 * The link is a foreign key now and the status says only how the lead is
 * going.
 */
class Lead extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $fillable = [
        'organization_id', 'account_id', 'reference', 'name', 'summary', 'source', 'status',
        'priority', 'deadline', 'website', 'problem_statement', 'proposed_solution',
        'owner_id', 'created_by',
        'fit_company', 'fit_budget', 'fit_solution', 'fit_timeline', 'fit_stakeholder',
        'budget_availability', 'stakeholder_engagement', 'timeline_estimate',
        'technical_readiness', 'decision_authority_access', 'qualification_notes',
    ];

    protected $casts = [
        'deadline'        => 'date',
        'fit_company'     => 'integer',
        'fit_budget'      => 'integer',
        'fit_solution'    => 'integer',
        'fit_timeline'    => 'integer',
        'fit_stakeholder' => 'integer',
    ];

    protected $attributes = ['status' => 'new', 'priority' => 'medium'];

    /**
     * The four the board has columns for, plus converted.
     *
     * `converted` is not a column: a converted lead has left the board and
     * lives on as its opportunity. `cold` and `lost` were two words for the
     * same outcome and are now one — a board cannot have a column for a
     * distinction nobody could state.
     */
    public const STATUSES  = ['new', 'contacted', 'qualified', 'unqualified', 'converted'];
    public const BOARD_STATUSES = ['new', 'contacted', 'qualified', 'unqualified'];
    public const PRIORITIES = ['low', 'medium', 'high'];

    /** Still worth working — the question the legacy status could not answer. */
    public const OPEN_STATUSES = ['new', 'contacted', 'qualified'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'owner_id');
    }

    /** The research team, as the detail screen shows it. */
    public function team(): HasMany
    {
        return $this->hasMany(LeadTeamMember::class);
    }

    /**
     * What it became, if anything.
     *
     * hasOne rather than a column on the lead: the opportunity owns the link,
     * so converting twice is impossible without two opportunities existing.
     */
    public function opportunity(): HasOne
    {
        return $this->hasOne(Opportunity::class);
    }

    public function stakeholders(): HasMany
    {
        return $this->hasMany(LeadStakeholder::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class)->orderByDesc('occurred_at');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(LeadAttachment::class)->latest('id');
    }

    // ── Derived ───────────────────────────────────────────────────────────

    /** How well it fits, across five dimensions. Null until assessed. */
    public function getQualificationScoreAttribute(): ?int
    {
        return LeadQualification::score($this);
    }

    public function getQualificationBandAttribute(): ?string
    {
        return LeadQualification::band($this->qualification_score);
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', self::OPEN_STATUSES);
    }
}
