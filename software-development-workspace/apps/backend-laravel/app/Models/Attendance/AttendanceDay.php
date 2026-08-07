<?php

namespace App\Models\Attendance;

use App\Models\Account\Account;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One employee, one day.
 *
 * A future day is a plan and a past day is a record, and they are the same row.
 * The requirement asks both for advance work-location reporting and for
 * attendance history, and holding those in two tables guarantees they
 * eventually disagree about the same Thursday.
 *
 * Planned and actual sit side by side so the gap between them is a fact the
 * system holds — "said office, worked from home" is the single most useful
 * thing a hybrid-working report can say, and it is unrecoverable if only one
 * of the two is kept.
 */
class AttendanceDay extends Model
{
    use BelongsToOrganization;

    protected $table = 'attendance_days';

    protected $fillable = [
        'organization_id', 'employee_id', 'work_date',
        'planned_location', 'actual_location', 'account_id', 'location_note',
        'status', 'first_clock_in_at', 'last_clock_out_at',
        'worked_minutes', 'overtime_minutes',
        'is_enablement_day', 'enablement_status', 'enablement_approved_by',
        'enablement_actioned_at', 'enablement_note',
        'notes', 'created_by',
    ];

    protected $casts = [
        'work_date'              => 'date',
        'first_clock_in_at'      => 'datetime',
        'last_clock_out_at'      => 'datetime',
        'enablement_actioned_at' => 'datetime',
        'is_enablement_day'      => 'boolean',
    ];

    /** Where somebody works. Three, as the requirement names them. */
    public const LOCATIONS = ['office', 'home', 'customer_site'];

    /**
     * What the day turned out to be.
     *
     * `absent` and `no_clock_out` are separate because they are different
     * failures: one is somebody who did not come, the other somebody who did
     * and forgot to close the day. The designs count them separately, and
     * treating the second as the first would accuse people of not turning up.
     */
    public const STATUSES = [
        'on_time', 'late', 'early', 'absent', 'no_clock_in', 'no_clock_out',
        'on_leave', 'holiday', 'weekend',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Which customer, where the day was spent on their site. */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function punches(): HasMany
    {
        return $this->hasMany(AttendancePunch::class)->orderBy('clocked_in_at');
    }

    /** What the day is planned to be spent on. */
    public function blocks(): HasMany
    {
        return $this->hasMany(AttendanceBlock::class, 'attendance_day_id')->orderBy('starts_at');
    }

    public function audits(): HasMany
    {
        return $this->hasMany(AttendanceAudit::class)->latest('changed_at');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'enablement_approved_by');
    }

    public function scopeBetween(Builder $q, string $from, string $to): Builder
    {
        return $q->whereBetween('work_date', [$from, $to]);
    }

    /** Days somebody was actually at work, for the attendance figures. */
    public function scopeWorked(Builder $q): Builder
    {
        return $q->whereIn('status', ['on_time', 'late', 'early']);
    }

    /** An open punch — clocked in and not yet out. */
    public function getIsClockedInAttribute(): bool
    {
        return $this->punches->contains(fn (AttendancePunch $p) => $p->clocked_out_at === null);
    }

    /**
     * How much of the day is spoken for.
     *
     * Worth having beside `worked_minutes`: "planned nine hours, worked seven"
     * is the comparison the productivity requirement reaches for, and it needs
     * both halves to exist.
     */
    public function getPlannedMinutesAttribute(): ?int
    {
        if (! $this->relationLoaded('blocks')) {
            return null;
        }

        return $this->blocks->sum(fn (AttendanceBlock $b) => $b->minutes) ?: null;
    }

    /**
     * Distinct tasks blocked out today.
     *
     * Counted from the blocks rather than kept on the row, so it cannot drift.
     * Distinct, because a task split across a morning and an afternoon is one
     * task planned, not two.
     */
    public function getPlannedTaskCountAttribute(): int
    {
        if (! $this->relationLoaded('blocks')) {
            return 0;
        }

        return $this->blocks->whereNotNull('task_id')->pluck('task_id')->unique()->count();
    }

    /**
     * Blocks covering the same clock twice.
     *
     * Flagged rather than refused. Double-booking is usually a mistake and
     * occasionally exactly what happened, and a planner that will not let you
     * write down a clash is one people stop using — but a clash nobody is shown
     * is a plan that quietly cannot happen.
     */
    public function getClashingBlockIdsAttribute(): array
    {
        if (! $this->relationLoaded('blocks')) {
            return [];
        }

        $blocks  = $this->blocks->values();
        $clashes = [];

        foreach ($blocks as $i => $a) {
            foreach ($blocks->slice($i + 1) as $b) {
                if ($a->overlaps($b)) {
                    $clashes[$a->id] = true;
                    $clashes[$b->id] = true;
                }
            }
        }

        return array_keys($clashes);
    }

    /**
     * Whether the plan matched what happened.
     *
     * Null until both are known, because "no plan" and "plan kept" are not the
     * same thing and a report that shows them alike is worse than one that
     * shows neither.
     */
    public function getPlanWasKeptAttribute(): ?bool
    {
        if ($this->planned_location === null || $this->actual_location === null) {
            return null;
        }

        return $this->planned_location === $this->actual_location;
    }
}
