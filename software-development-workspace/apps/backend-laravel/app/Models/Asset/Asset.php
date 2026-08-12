<?php

namespace App\Models\Asset;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Asset extends Model
{
    use BelongsToOrganization, HasFactory, SoftDeletes;

    public const STATUS_AVAILABLE   = 'available';
    public const STATUS_ASSIGNED    = 'assigned';
    public const STATUS_MAINTENANCE = 'maintenance';
    public const STATUS_RETIRED     = 'retired';

    /*
     * Allocated to somebody who has not yet said they have it.
     *
     * Assigning used to set `assigned_to` immediately, so the register claimed
     * somebody held equipment that was still on a shelf — and if it went
     * missing in between, it went missing from their record.
     */
    public const STATUS_PENDING_ACCEPTANCE = 'pending_acceptance';

    public const STATUSES = [
        self::STATUS_AVAILABLE,
        self::STATUS_ASSIGNED,
        self::STATUS_MAINTENANCE,
        self::STATUS_RETIRED,
        self::STATUS_PENDING_ACCEPTANCE,
    ];

    /**
     * What kind of thing this is, as opposed to `category`, which is the label
     * within a kind — "Laptop", "Monitor", "Design tool".
     *
     * A subscription is not a laptop with the hardware fields left blank. It is
     * answerable to different questions: a laptop is asked where it is and who
     * has it, a subscription is asked when it renews and how many seats are
     * going unused. Mixing them in one report gets both wrong.
     */
    public const CLASS_PHYSICAL     = 'physical';
    public const CLASS_SOFTWARE     = 'software';
    public const CLASS_SUBSCRIPTION = 'subscription';

    public const CLASSES = [
        self::CLASS_PHYSICAL,
        self::CLASS_SOFTWARE,
        self::CLASS_SUBSCRIPTION,
    ];

    /** The classes bought in quantity and consumed by people. */
    public const SEATED_CLASSES = [self::CLASS_SOFTWARE, self::CLASS_SUBSCRIPTION];

    protected $fillable = [
        'organization_id', 'asset_class', 'parent_asset_id',
        'name', 'asset_tag', 'category', 'asset_category_id', 'brand', 'model',
        'serial_number', 'status', 'lifecycle_stage', 'condition',
        'procured_date', 'in_stock_date', 'next_maintenance',
        'assigned_to', 'assigned_date', 'department_id', 'location',
        'handover_required', 'handover_signed_at', 'accepted_by', 'acceptance_note',
        'purchase_date', 'purchase_value', 'vendor', 'invoice_number',
        'warranty_period', 'warranty_expiry_date',
        'starts_on', 'expires_on', 'auto_renews', 'renewal_notice_days', 'renewal_cost',
        'estimated_renewal_cost', 'renewal_reminders_on',
        'description', 'seats_total',
        'vendor_contact', 'support_contact', 'sla',
        'processor', 'ram', 'storage', 'operating_system',
        'custom_fields', 'notes',
    ];

    protected $casts = [
        'purchase_date'        => 'date',
        'procured_date'        => 'date',
        'in_stock_date'        => 'date',
        'assigned_date'        => 'date',
        'next_maintenance'     => 'date',
        'warranty_expiry_date' => 'date',
        'handover_signed_at'   => 'datetime',
        'handover_required'    => 'boolean',
        'purchase_value'       => 'decimal:2',
        'starts_on'            => 'date',
        'expires_on'           => 'date',
        'auto_renews'          => 'boolean',
        'renewal_cost'         => 'decimal:2',
        'custom_fields'        => 'array',
    ];

    /** See the note on AssetRequest — a column default does not reach the
     *  model instance after create(). */
    protected $attributes = [
        'status'            => self::STATUS_AVAILABLE,
        'handover_required' => false,
        'asset_class'       => self::CLASS_PHYSICAL,
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** The employee currently holding this asset, if any. */
    public function holder(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_to');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** Where it has been. Oldest first, because a timeline reads forwards. */
    public function events(): HasMany
    {
        return $this->hasMany(AssetEvent::class)->orderBy('occurred_on')->orderBy('id');
    }

    /** A dock belongs to a laptop; a seat belongs to a subscription. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_asset_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_asset_id');
    }

    /**
     * The extra fields this asset's category asks for.
     *
     * Matched on name and class rather than a foreign key, because `category`
     * is still free text on the asset — anything registered before categories
     * were configurable, or through another route, simply has no definitions
     * and shows none.
     */
    public function categoryFields()
    {
        $q = AssetCategoryField::query()->orderBy('sort_order')->orderBy('id');

        /*
         * The link where there is one, the name where there is not.
         *
         * Anything registered before categories were configurable — and
         * anything typed freehand under "other" — has no link, and matching on
         * the name keeps its fields working rather than making them vanish.
         */
        return $this->asset_category_id
            ? $q->where('asset_category_id', $this->asset_category_id)
            : $q->whereHas('category', fn ($c) => $c
                ->where('name', $this->category)
                ->where('asset_class', $this->asset_class));
    }

    public function photos()
    {
        return $this->hasMany(AssetPhoto::class)->orderByDesc('is_primary')->orderBy('id');
    }

    /** The configured category, where the asset is filed under a real one. */
    public function assetCategory(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'asset_category_id');
    }

    /** Who hears when this comes up for renewal. */
    public function watchers(): HasMany
    {
        return $this->hasMany(AssetRenewalWatcher::class);
    }

    /** Every renewal term this has been through. */
    public function renewals(): HasMany
    {
        return $this->hasMany(AssetRenewal::class);
    }

    /** Every seat ever handed out of this batch, held or given back. */
    public function seats(): HasMany
    {
        return $this->hasMany(AssetSeatAllocation::class);
    }

    // ── Derived ───────────────────────────────────────────────────────────────

    /** Bought in quantity and consumed by people. */
    public function isSeated(): bool
    {
        return in_array($this->asset_class, self::SEATED_CLASSES, true);
    }

    /**
     * Seats currently held by somebody.
     *
     * Counted from allocations rather than stored. A hand-kept number drifts
     * the first time somebody leaves and nobody decrements it, and the register
     * then reports a full licence while six seats sit unused — which is money.
     */
    public function seatsUsed(): int
    {
        return $this->relationLoaded('seats')
            ? $this->seats->whereNull('released_on')->count()
            : $this->seats()->active()->count();
    }

    /**
     * Seats paid for and not handed out.
     *
     * Null when the question does not apply, which is not the same as nought —
     * a laptop with no spare seats is a laptop, not a fully-subscribed licence.
     */
    public function seatsSpare(): ?int
    {
        if (! $this->isSeated() || $this->seats_total === null) {
            return null;
        }

        return max(0, $this->seats_total - $this->seatsUsed());
    }

    /**
     * Hand a seat to somebody.
     *
     * Refuses to over-allocate. A licence with more people on it than seats
     * paid for is the compliance finding the register exists to prevent, and
     * the moment to catch it is the moment somebody is added — not in an audit
     * eighteen months later.
     */
    public function allocateSeat(int $employeeId, ?string $on = null, ?string $note = null, ?int $by = null): AssetSeatAllocation
    {
        abort_unless($this->isSeated(), 422, 'Only licences and subscriptions have seats.');

        $existing = $this->seats()->active()->where('employee_id', $employeeId)->exists();
        abort_if($existing, 422, 'They already hold a seat on this licence.');

        if ($this->seats_total !== null) {
            abort_if(
                $this->seatsUsed() >= $this->seats_total,
                422,
                'Every seat on this batch is allocated. Release one or buy more.',
            );
        }

        return $this->seats()->create([
            'organization_id' => $this->organization_id,
            'employee_id'     => $employeeId,
            'allocated_on'    => $on ?: now()->toDateString(),
            'note'            => $note,
            'allocated_by'    => $by,
        ]);
    }

    /**
     * Days until this stops working — negative once it has.
     *
     * Derived on read rather than stored, because a stored "days left" is wrong
     * from the moment it is written and needs a nightly job to stay honest.
     */
    public function daysToExpiry(?string $asOf = null): ?int
    {
        if ($this->expires_on === null) {
            return null;
        }

        $today = \Carbon\CarbonImmutable::parse($asOf ?: now()->toDateString());

        return (int) $today->diffInDays($this->expires_on->startOfDay(), false);
    }

    /**
     * Coming up for renewal.
     *
     * `renewal_notice_days` is how much warning this particular thing needs — a
     * domain wants a fortnight, an enterprise agreement wants a quarter — and
     * falls back to thirty days rather than to never.
     */
    public function scopeExpiringSoon(Builder $q, ?int $withinDays = null): Builder
    {
        return $q->whereNotNull('expires_on')
            ->where('status', '!=', self::STATUS_RETIRED)
            ->whereRaw(
                'expires_on <= CURRENT_DATE + make_interval(days => COALESCE(?, renewal_notice_days, 30))',
                [$withinDays],
            );
    }

    public function issues(): HasMany
    {
        return $this->hasMany(AssetIssue::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(AssetRequest::class);
    }

    // ── Derived state ─────────────────────────────────────────────────────────

    public function isAssigned(): bool
    {
        return $this->assigned_to !== null;
    }

    /**
     * Warranty state is derived rather than stored. A stored boolean would be
     * wrong the day after it was written, and nothing runs nightly to correct it.
     */
    public function warrantyHasExpired(): bool
    {
        return $this->warranty_expiry_date !== null
            && $this->warranty_expiry_date->isPast();
    }

    public function maintenanceIsDue(): bool
    {
        return $this->next_maintenance !== null
            && ! $this->next_maintenance->isFuture();
    }
}
