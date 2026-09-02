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
        'qualification_notes',
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
     * The three the board has columns for, plus the two that leave it.
     *
     * `converted` is not a column: a converted lead has left the board and
     * lives on as its opportunity. `cold` and `lost` were two words for the
     * same outcome and are now one — a board cannot have a column for a
     * distinction nobody could state.
     *
     * **Neither is `qualified` a column any more.** Qualifying a lead is what
     * turns it into a deal, so a lead cannot come to rest in that state: the
     * moment it qualifies it converts, and conversion takes it off the board.
     * The column had held 0 of 160 leads since the module opened, which is the
     * same finding arrived at from the data — everything went straight from
     * `new` to `converted`, because there was never anything to do in between.
     *
     * The value stays in STATUSES so legacy rows and the PLM importer remain
     * readable; nothing writes it.
     */
    public const STATUSES  = ['new', 'contacted', 'qualified', 'unqualified', 'converted'];
    public const BOARD_STATUSES = ['new', 'contacted', 'unqualified'];
    public const PRIORITIES = ['low', 'medium', 'high'];

    /**
     * Still worth working — the question the legacy status could not answer.
     * `qualified` stays here for any legacy row carrying it: such a lead is
     * open, it simply has no route back onto the board.
     */
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

    // ── Correspondence ────────────────────────────────────────────────────

    /** Newest first: the next call matters more than the first one. */
    public function meetings(): HasMany
    {
        return $this->hasMany(LeadMeeting::class)->orderByDesc('scheduled_at');
    }

    public function emails(): HasMany
    {
        return $this->hasMany(LeadEmail::class)->orderByDesc('received_at');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(LeadRequest::class)->latest('id');
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
