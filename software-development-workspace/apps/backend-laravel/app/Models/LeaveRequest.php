<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use App\Models\Leave\LeaveRequestAttachment;
use App\Models\Leave\LeaveRequestMessage;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LeaveRequest extends Model
{
    use BelongsToOrganization, SoftDeletes;

    public const STATUS_PENDING   = 'pending';
    public const STATUS_APPROVED  = 'approved';
    public const STATUS_REJECTED  = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'employee_id',
        'organization_id',
        'leave_type_id',
        'approved_by',
        'from_date',
        'to_date',
        'days_requested',
        'is_half_day',
        'half_day_period',
        'scattered_dates',
        'reason',
        'status',
        'rejection_reason',
        'actioned_at',
    ];

    protected $casts = [
        'from_date'       => 'date',
        'to_date'         => 'date',
        'actioned_at'     => 'datetime',
        'days_requested'  => 'decimal:1',
        'is_half_day'     => 'boolean',
        'scattered_dates' => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approved_by');
    }

    /**
     * The supporting paperwork — a sick note, a death notice.
     *
     * Newest last, because they are read as a pile somebody added to rather
     * than as a feed.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(LeaveRequestAttachment::class)->oldest();
    }

    /** What was said about it, oldest first, the way a thread reads. */
    public function messages(): HasMany
    {
        return $this->hasMany(LeaveRequestMessage::class)->oldest();
    }

    /**
     * The reference people quote at each other.
     *
     * There is no stored reference column and adding one would need a
     * generator, a uniqueness guarantee and a backfill for the thousand
     * requests already imported. The id is already unique and already
     * permanent, so it is padded and shown rather than duplicated.
     */
    public function getReferenceAttribute(): string
    {
        return '#' . str_pad((string) $this->id, 4, '0', STR_PAD_LEFT);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }
}
