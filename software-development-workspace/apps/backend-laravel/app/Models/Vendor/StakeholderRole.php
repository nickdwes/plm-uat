<?php

namespace App\Models\Vendor;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Training\VendorPartner;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One role a stakeholder plays, for a period.
 *
 * A party is not "a distributor" — it is a distributor *since March*, and was
 * a subcontractor before that. Roles are additive (several may be in force at
 * once) and end-dated rather than deleted, because the question an auditor
 * asks is "what were they when we signed this?", not "what are they now".
 */
class StakeholderRole extends Model
{
    use BelongsToOrganization;

    /**
     * The vocabulary.
     *
     * Wider than the legacy `vendor_type` list in two ways that matter: a
     * contractor is either a company or an individual (different due
     * diligence entirely), and OEM is a role like any other rather than the
     * boolean it had to be when classification was one column.
     */
    public const ROLES = [
        'oem',
        'distributor',
        'channel_partner',
        'supplier',
        'service_provider',
        'contractor_company',
        'contractor_individual',
        'consultant',
        'subcontractor',
    ];

    /** Roles that only make sense for a person, and vice versa. */
    public const INDIVIDUAL_ONLY = ['contractor_individual'];

    protected $fillable = [
        'organization_id',
        'vendor_partner_id',
        'role',
        'valid_from',
        'valid_until',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'valid_from'  => 'date',
        'valid_until' => 'date',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(VendorPartner::class, 'vendor_partner_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** In force on the given day (today by default). */
    public function scopeInForce(Builder $query, ?string $on = null): Builder
    {
        $on ??= now()->toDateString();

        return $query->whereDate('valid_from', '<=', $on)
            ->where(fn ($q) => $q->whereNull('valid_until')->whereDate('valid_until', '>=', $on, 'or'));
    }

    public function isInForce(?string $on = null): bool
    {
        $on = $on ? \Illuminate\Support\Carbon::parse($on) : now();

        return $this->valid_from->lte($on)
            && ($this->valid_until === null || $this->valid_until->gte($on));
    }

    /**
     * "channel_partner" → "Channel Partner", "oem" → "OEM".
     *
     * Title-casing alone renders the acronym as "Oem", which reads as a
     * typo everywhere it appears.
     */
    public function label(): string
    {
        return self::LABELS[$this->role]
            ?? ucwords(str_replace('_', ' ', $this->role));
    }

    private const LABELS = [
        'oem'                   => 'OEM',
        'contractor_company'    => 'Contractor (Company)',
        'contractor_individual' => 'Contractor (Individual)',
    ];
}
