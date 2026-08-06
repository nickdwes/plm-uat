<?php

namespace App\Models\Account;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A customer.
 *
 * Exists ahead of Travel because a travel request names the customer the trip
 * is for, and 94 of 102 legacy requests did so — it is not an optional field
 * in practice.
 */
class Account extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $fillable = [
        'organization_id', 'name', 'reference', 'status', 'enrolled_on', 'last_contact_at',
        'account_manager_id', 'account_type_id', 'account_sector_id', 'account_subsector_id',
        'country', 'physical_location', 'number_of_branches', 'number_of_employees',
        'fund_source', 'lead_source', 'pipeline_stage',
        'vat_registered', 'tax_pin', 'details', 'created_by',
    ];

    protected $casts = [
        'vat_registered'      => 'boolean',
        'number_of_branches'  => 'integer',
        'number_of_employees' => 'integer',
        'enrolled_on'         => 'date',
        'last_contact_at'     => 'datetime',
    ];

    protected $attributes = [
        'status'         => 'pending',
        'vat_registered' => false,
    ];

    /**
     * Whether the record is live — not what the relationship is.
     *
     * `prospect` used to live here as well as in the type list, so a customer
     * could be a Client of status prospect. Type now carries the relationship
     * (Prospect, Client, Partner, Supplier) and this carries only standing.
     */
    public const STATUSES = ['pending', 'active', 'inactive'];

    // ── Relationships ─────────────────────────────────────────────────────

    public function accountManager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'account_manager_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(AccountType::class, 'account_type_id');
    }

    public function sector(): BelongsTo
    {
        return $this->belongsTo(AccountSector::class, 'account_sector_id');
    }

    public function subsector(): BelongsTo
    {
        return $this->belongsTo(AccountSubsector::class, 'account_subsector_id');
    }

    /**
     * The people who work this account.
     *
     * The manager owns it; the team serves it. Keeping them apart means one
     * place names who is accountable and the two cannot disagree.
     */
    public function team(): HasMany
    {
        return $this->hasMany(AccountTeamMember::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(AccountContact::class);
    }

    /**
     * What we have agreed to do for them.
     *
     * Newest first: the live agreement is what people open the record to
     * check, not the one that ran out three years ago.
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(AccountContract::class)->orderByDesc('end_date');
    }

    public function primaryContact(): HasMany
    {
        return $this->contacts()->where('is_primary', true);
    }

    /**
     * What has happened with this customer, newest first.
     *
     * The feed is the source of `last_contact_at`; see AccountActivity for why
     * only manual entries move it.
     */
    public function activities(): HasMany
    {
        return $this->hasMany(AccountActivity::class)->orderByDesc('occurred_at');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(AccountAttachment::class);
    }

    /**
     * Trips taken for this customer.
     *
     * The link has existed since Travel was built — 94 of 102 legacy requests
     * named the customer they were for — but nothing read it from this side.
     */
    public function travelRequests(): HasMany
    {
        return $this->hasMany(\App\Models\Travel\TravelRequest::class);
    }

    /**
     * Initials for the avatar the screens show, so every surface derives the
     * same two or three letters rather than each inventing its own.
     */
    public function getInitialsAttribute(): string
    {
        $words = preg_split('/[\s\-]+/', trim((string) $this->name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return strtoupper(implode('', array_map(
            static fn (string $w) => mb_substr($w, 0, 1),
            array_slice($words, 0, 3),
        )));
    }

    // ── Scopes ────────────────────────────────────────────────────────────

    /**
     * Customers this employee manages.
     *
     * Backs `accounts.view_own`, which is the scope an account manager gets
     * when they should see their own book and not the whole customer base.
     */
    public function scopeManagedBy(Builder $q, ?int $employeeId): Builder
    {
        // A null employee must match nothing rather than everything: a user
        // with no employee record would otherwise see every customer.
        return $q->where('account_manager_id', $employeeId ?? 0);
    }
}
