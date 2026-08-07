<?php

namespace App\Models\Attendance;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * What the working day is supposed to look like.
 *
 * Without it, "On Time" and "Late" are arbitrary — and the designs put both on
 * the front page as counted figures. A count needs a rule somebody decided.
 */
class AttendancePolicy extends Model
{
    use BelongsToOrganization;

    protected $table = 'attendance_policies';

    protected $fillable = [
        'organization_id', 'work_starts_at', 'work_ends_at', 'grace_minutes',
        'standard_hours', 'overtime_after_minutes', 'working_days', 'plan_ahead_days',
    ];

    protected $casts = [
        'working_days'   => 'array',
        'standard_hours' => 'decimal:2',
    ];

    /** Monday to Friday, which is what nobody having said means here. */
    public const DEFAULT_WORKING_DAYS = [1, 2, 3, 4, 5];

    /**
     * The organisation's policy, or a sensible one it has not set yet.
     *
     * Never null. A missing policy would otherwise make every status
     * unanswerable, and an empty attendance page is a worse answer than one
     * built on a stated default.
     */
    public static function forOrganization(int $organizationId): self
    {
        return static::firstOrCreate(
            ['organization_id' => $organizationId],
            ['working_days' => self::DEFAULT_WORKING_DAYS],
        );
    }

    public function worksOn(\DateTimeInterface $date): bool
    {
        $days = $this->working_days ?: self::DEFAULT_WORKING_DAYS;

        return in_array((int) $date->format('N'), $days, true);
    }
}
