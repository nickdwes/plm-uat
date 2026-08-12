<?php

namespace App\Models\Asset;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Employee;
use App\Models\Training\VendorPartner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One piece of work done, or waiting to be done, on an asset.
 *
 * The register can say a service is due. This says what happened at the last
 * one, who did it, and what it cost — which together answer the only question
 * that matters when something breaks for the third time: repair or replace.
 */
class AssetMaintenance extends Model
{
    use BelongsToOrganization;

    protected $table = 'asset_maintenance';

    protected $fillable = [
        'organization_id', 'asset_id', 'type', 'reason', 'details', 'status',
        'scheduled_for', 'performed_on', 'cost',
        'vendor_partner_id', 'technician_id', 'asset_issue_id',
        'notes', 'recorded_by',
    ];

    protected $casts = [
        'scheduled_for' => 'date',
        'performed_on'  => 'date',
        'cost'          => 'decimal:2',
    ];

    protected $attributes = ['status' => self::AWAITING_HR, 'type' => self::CORRECTIVE];

    /** Planned, or something broke. The ratio between them is the health measure. */
    public const PREVENTIVE = 'preventive';
    public const CORRECTIVE = 'corrective';
    public const TYPES = [self::PREVENTIVE, self::CORRECTIVE];

    /*
     * The two waiting states are separate on purpose. Both read as "not
     * started" on a board and they need different people to unblock them:
     * one wants a part bought, the other wants the spend authorised.
     */
    public const AWAITING_PROCUREMENT = 'awaiting_procurement';
    public const AWAITING_HR          = 'awaiting_hr';
    public const IN_PROGRESS          = 'in_progress';
    public const COMPLETED            = 'completed';
    public const CANCELLED            = 'cancelled';

    public const STATUSES = [
        self::AWAITING_PROCUREMENT, self::AWAITING_HR,
        self::IN_PROGRESS, self::COMPLETED, self::CANCELLED,
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(VendorPartner::class, 'vendor_partner_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'technician_id');
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(AssetIssue::class, 'asset_issue_id');
    }

    /** Still needs somebody to do something. */
    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereNotIn('status', [self::COMPLETED, self::CANCELLED]);
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, [self::COMPLETED, self::CANCELLED], true);
    }
}
