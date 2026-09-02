<?php

namespace App\Models\Pipeline;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something needed to pursue a lead, and somebody who has to say yes.
 *
 * Pre-sales spend against a deal that may never close, which is the reason it
 * is approved before rather than claimed after.
 */
class LeadRequest extends Model
{
    use BelongsToOrganization;

    protected $table = 'lead_requests';

    /**
     * What can be asked for.
     *
     * The four named ones cover what a pursuit actually costs before there is
     * a deal; `other` exists so a fifth thing is a sentence in `purpose`
     * rather than a reason to leave the request unrecorded.
     */
    public const KINDS = [
        'transport'   => 'Transport',
        'travel'      => 'Travel',
        'lunch'       => 'Lunch / hospitality',
        'site_survey' => 'Site survey',
        'other'       => 'Other',
    ];

    public const STATUS_PENDING   = 'pending';
    public const STATUS_APPROVED  = 'approved';
    public const STATUS_DECLINED  = 'declined';
    public const STATUS_FULFILLED = 'fulfilled';

    public const STATUSES = [
        self::STATUS_PENDING, self::STATUS_APPROVED,
        self::STATUS_DECLINED, self::STATUS_FULFILLED,
    ];

    protected $fillable = [
        'organization_id', 'lead_id', 'reference', 'kind', 'purpose', 'location',
        'needed_on', 'people', 'estimated_cost', 'currency', 'notes', 'status',
        'decision_comment', 'decided_at', 'decided_by', 'requested_by',
    ];

    protected $casts = [
        'needed_on'      => 'date',
        'decided_at'     => 'datetime',
        'people'         => 'integer',
        'estimated_cost' => 'decimal:2',
    ];

    /** Decided means somebody answered — approving and declining both count. */
    public function isDecided(): bool
    {
        return $this->status !== self::STATUS_PENDING;
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
