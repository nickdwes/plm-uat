<?php

namespace App\Models\Support;

use App\Models\Account\Account;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A deliberate change to a service.
 */
class Change extends Model
{
    use BelongsToOrganization;

    protected $table = 'support_changes';

    /** Pre-approved and routine. No CAB, because asking each time trains people to stop asking. */
    public const STANDARD = 'standard';

    public const NORMAL = 'normal';

    /**
     * Done first, approved after, recorded as such.
     *
     * The honest category. Emergencies happen, and a process with no route for
     * them gets bypassed entirely rather than followed badly.
     */
    public const EMERGENCY = 'emergency';

    public const DRAFT      = 'draft';
    public const AWAITING   = 'awaiting_approval';
    public const APPROVED   = 'approved';
    public const REJECTED   = 'rejected';
    public const SCHEDULED  = 'scheduled';
    public const IN_PROGRESS = 'in_progress';
    public const IMPLEMENTED = 'implemented';
    public const FAILED     = 'failed';
    public const CANCELLED  = 'cancelled';

    protected $fillable = [
        'organization_id', 'reference', 'title', 'description',
        'change_type', 'risk', 'implementation_plan', 'rollback_plan', 'status',
        'window_start', 'window_end', 'account_id', 'support_problem_id',
        'requested_by', 'implementer_id', 'outcome', 'implemented_at',
    ];

    protected $casts = [
        'window_start'   => 'datetime',
        'window_end'     => 'datetime',
        'implemented_at' => 'datetime',
    ];

    protected $attributes = [
        'change_type' => self::NORMAL,
        'risk'        => 'medium',
        'status'      => self::DRAFT,
    ];

    public function problem(): BelongsTo
    {
        return $this->belongsTo(Problem::class, 'support_problem_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function implementer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'implementer_id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(TicketLink::class, 'support_change_id');
    }

    public function approvals(): MorphMany
    {
        return $this->morphMany(Approval::class, 'approvable');
    }

    /** Standard changes are pre-approved; everything else is asked about. */
    public function needsApproval(): bool
    {
        return $this->change_type !== self::STANDARD;
    }

    public static function nextReference(int $organizationId): string
    {
        $year = now()->format('Y');

        $last = static::withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->where('reference', 'like', "CHG-{$year}-%")
            ->orderByDesc('id')->value('reference');

        return sprintf('CHG-%s-%04d', $year, $last ? ((int) substr($last, -4)) + 1 : 1);
    }
}
