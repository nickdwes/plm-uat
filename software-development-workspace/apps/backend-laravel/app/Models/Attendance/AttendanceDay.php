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

        // Stamped by the nightly sweep when somebody is asked to close their
        // day, and when the manager is told they did not.
        'reminded_at', 'escalated_at',
    ];

    protected $casts = [
        'work_date'              => 'date',
        'first_clock_in_at'      => 'datetime',
        'last_clock_out_at'      => 'datetime',
        'enablement_actioned_at' => 'datetime',
        'reminded_at'            => 'datetime',
        'escalated_at'           => 'datetime',
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

    /** What was promised for the day, in the order it was meant to be taken. */
    public function commitments(): HasMany
    {
        return $this->hasMany(AttendanceCommitment::class, 'attendance_day_id')
            ->orderBy('position')->orderBy('id');
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
     * How the day went: promised, delivered, and owed an explanation.
     *
     * One accessor rather than four, because the four numbers are only ever
     * read together and a caller that fetched three of them would draw a
     * picture that does not add up.
     */
    public function getCommitmentTallyAttribute(): array
    {
        if (! $this->relationLoaded('commitments')) {
            return ['promised' => 0, 'done' => 0, 'missed' => 0, 'unexplained' => 0, 'open' => 0];
        }

        $all = $this->commitments;

        return [
            'promised' => $all->count(),
            'done'     => $all->where('outcome', 'done')->count(),
            'missed'   => $all->where('outcome', 'not_done')->count(),

            /*
             * Missed with nobody told, plus anything still unanswered on a day
             * that is over. This is the number that matters: work slips, and a
             * manager reacting to slippage is reacting to noise. Work that
             * slipped in silence was previously uncountable.
             */
            'unexplained' => $all->filter(
                fn (AttendanceCommitment $c) => ! $c->is_explained && ! $this->work_date->isFuture()
                    && ! $this->work_date->isToday(),
            )->count(),

            'open' => $all->whereNull('outcome')->count(),
        ];
    }

    /**
     * Whether the day has been answered for.
     *
     * A day nobody closed out is not a day where everything went to plan, and
     * showing the two alike is how the whole record stops being read.
     */
    public function getIsReportedAttribute(): bool
    {
        if (! $this->relationLoaded('commitments') || $this->commitments->isEmpty()) {
            return false;
        }

        return $this->commitments->whereNull('outcome')->isEmpty();
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
