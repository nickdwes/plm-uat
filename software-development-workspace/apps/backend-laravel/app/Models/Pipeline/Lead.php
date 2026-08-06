<?php

namespace App\Models\Pipeline;

use App\Models\Account\Account;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Employee;
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
        'organization_id', 'account_id', 'reference', 'name', 'source', 'status',
        'priority', 'deadline', 'website', 'problem_statement', 'proposed_solution',
        'owner_id', 'created_by',
    ];

    protected $casts = ['deadline' => 'date'];

    protected $attributes = ['status' => 'new', 'priority' => 'medium'];

    public const STATUSES  = ['new', 'contacted', 'qualified', 'converted', 'cold', 'lost'];
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

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', self::OPEN_STATUSES);
    }
}
