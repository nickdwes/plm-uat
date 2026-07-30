<?php

namespace App\Models\Asset;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
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

    public const STATUSES = [
        self::STATUS_AVAILABLE,
        self::STATUS_ASSIGNED,
        self::STATUS_MAINTENANCE,
        self::STATUS_RETIRED,
    ];

    protected $fillable = [
        'organization_id', 'name', 'asset_tag', 'category', 'brand', 'model',
        'serial_number', 'status', 'lifecycle_stage', 'condition',
        'procured_date', 'in_stock_date', 'next_maintenance',
        'assigned_to', 'assigned_date', 'department_id', 'location',
        'handover_required', 'handover_signed_at',
        'purchase_date', 'purchase_value', 'vendor', 'invoice_number',
        'warranty_period', 'warranty_expiry_date',
        'processor', 'ram', 'storage', 'operating_system',
        'notes',
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
    ];

    /** See the note on AssetRequest — a column default does not reach the
     *  model instance after create(). */
    protected $attributes = [
        'status'            => self::STATUS_AVAILABLE,
        'handover_required' => false,
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
