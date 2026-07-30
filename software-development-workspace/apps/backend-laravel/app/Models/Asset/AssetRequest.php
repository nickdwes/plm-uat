<?php

namespace App\Models\Asset;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssetRequest extends Model
{
    use BelongsToOrganization, HasFactory;

    public const STATUS_PENDING   = 'pending';
    public const STATUS_APPROVED  = 'approved';
    public const STATUS_REJECTED  = 'rejected';
    public const STATUS_FULFILLED = 'fulfilled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_FULFILLED,
    ];

    protected $fillable = [
        'organization_id', 'request_number', 'employee_id', 'category', 'item',
        'quantity', 'reason', 'urgency', 'status',
        'approved_by', 'approved_at', 'decision_note', 'asset_id', 'notes',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'quantity'    => 'integer',
    ];

    /**
     * Defaults live here as well as on the column.
     *
     * A database default populates the row but not the model instance, so
     * after create() the object reports status = null while the record says
     * 'pending' — and any state check made before a refresh is wrong.
     */
    protected $attributes = [
        'status'   => self::STATUS_PENDING,
        'urgency'  => 'medium',
        'quantity' => 1,
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    /** Who asked. Named requester rather than employee so the two people
     *  columns on this model cannot be confused at a call site. */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approved_by');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AssetRequestMessage::class);
    }

    // ── State ─────────────────────────────────────────────────────────────────

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Only a pending request can be decided.
     *
     * Without this the same request can be approved twice, or rejected after
     * approval, and approved_at silently becomes the time of the last click
     * rather than the decision.
     */
    public function isDecidable(): bool
    {
        return $this->isPending();
    }
}
