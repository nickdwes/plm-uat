<?php

namespace App\Models\Support;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The cause behind repeated incidents.
 *
 * The old module had "Problem" as a ticket type, which makes a cause into one
 * more thing in the queue. Forty tickets about one failing switch were forty
 * separate pieces of work, each closed on its own, and nothing recorded that
 * they shared a cause — so the switch stayed in.
 */
class Problem extends Model
{
    use BelongsToOrganization;

    protected $table = 'support_problems';

    public const INVESTIGATING = 'investigating';

    /** Cause understood, fix not yet possible, workaround known. */
    public const KNOWN_ERROR = 'known_error';

    public const RESOLVED = 'resolved';
    public const CLOSED   = 'closed';

    protected $fillable = [
        'organization_id', 'reference', 'title', 'statement', 'status',
        'root_cause', 'workaround', 'service_category_id', 'owner_id',
        'identified_at', 'resolved_at',
    ];

    protected $casts = ['identified_at' => 'datetime', 'resolved_at' => 'datetime'];

    protected $attributes = ['status' => self::INVESTIGATING];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(TicketLink::class, 'support_problem_id');
    }

    public function changes(): HasMany
    {
        return $this->hasMany(Change::class, 'support_problem_id');
    }

    /**
     * The workaround is worth having even before the fix is.
     *
     * A known error with a written workaround is the difference between the
     * desk giving the same answer every time and rediscovering it each time.
     */
    public function hasWorkaround(): bool
    {
        return filled($this->workaround);
    }

    public static function nextReference(int $organizationId): string
    {
        $year = now()->format('Y');

        $last = static::withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->where('reference', 'like', "PRB-{$year}-%")
            ->orderByDesc('id')->value('reference');

        return sprintf('PRB-%s-%04d', $year, $last ? ((int) substr($last, -4)) + 1 : 1);
    }
}
