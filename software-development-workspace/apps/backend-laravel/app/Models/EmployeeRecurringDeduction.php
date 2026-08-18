<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A standing order against one person's pay.
 *
 * The outstanding balance is derived, never stored. A stored figure and the
 * payslips behind it disagree the first time a run is recomputed or a period
 * reopened, and when they disagree it is somebody's money in the gap.
 */
class EmployeeRecurringDeduction extends Model
{
    use BelongsToOrganization;

    public const STATUS_ACTIVE    = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'organization_id', 'employee_id', 'payroll_deduction_code_id',
        'label', 'instalment', 'principal', 'starts_on', 'ends_on',
        'status', 'priority', 'reference', 'note', 'created_by',
    ];

    protected $casts = [
        'instalment' => 'decimal:2',
        'principal'  => 'decimal:2',
        'starts_on'  => 'date',
        'ends_on'    => 'date',
        'priority'   => 'integer',
    ];

    protected $attributes = [
        'status'   => self::STATUS_ACTIVE,
        'priority' => 50,
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function code(): BelongsTo
    {
        return $this->belongsTo(PayrollDeductionCode::class, 'payroll_deduction_code_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayrollRunItem::class, 'employee_recurring_deduction_id');
    }

    /**
     * What has actually come off, on payroll that was released.
     *
     * Draft and processing periods are excluded on purpose: those figures still
     * move. Counting a recovery before the run is approved would shrink the
     * balance on the strength of a calculation somebody may yet redo.
     */
    public function recovered(): float
    {
        return (float) $this->items()
            ->whereHas('run.period', fn ($q) => $q->whereIn('status', [
                PayrollPeriod::STATUS_APPROVED,
                PayrollPeriod::STATUS_PAID,
            ]))
            ->sum('amount');
    }

    /** Recovered on released payroll, ignoring one period's own contribution. */
    public function recoveredExcluding(PayrollPeriod $period): float
    {
        return (float) $this->items()
            // whereNotIn rather than !=: the subquery is empty before the run
            // exists, and `!= NULL` is unknown in SQL, which would silently
            // exclude every row and report nothing recovered.
            ->whereNotIn('payroll_run_id', function ($q) use ($period) {
                $q->select('id')->from('payroll_runs')
                    ->where('payroll_period_id', $period->id)
                    ->where('employee_id', $this->employee_id);
            })
            ->whereHas('run.period', fn ($q) => $q->whereIn('status', [
                PayrollPeriod::STATUS_APPROVED,
                PayrollPeriod::STATUS_PAID,
            ]))
            ->sum('amount');
    }

    /** Null where there is nothing to repay — a check-off runs until stopped. */
    public function outstanding(): ?float
    {
        if ($this->principal === null) {
            return null;
        }

        return round(max(0, (float) $this->principal - $this->recovered()), 2);
    }

    /**
     * What to take this month, or nought.
     *
     * The last instalment is the remainder rather than the full amount — a loan
     * of 50,000 repaid at 12,000 takes 2,000 in the fifth month, not 12,000.
     * Recovering past the principal is the error people notice, and rightly.
     */
    public function dueFor(PayrollPeriod $period): float
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return 0.0;
        }

        $start = $this->starts_on;
        $end   = $this->ends_on;

        // Judged on the period being paid, not on today: recomputing an old
        // period in December must take what that month was due, not nothing.
        if ($start && $period->end_date && $start->gt($period->end_date)) {
            return 0.0;
        }

        if ($end && $period->start_date && $end->lt($period->start_date)) {
            return 0.0;
        }

        $instalment = (float) $this->instalment;

        if ($this->principal === null) {
            return round($instalment, 2);
        }

        /*
         * Measured against everything recovered *except* this period's own
         * line. Recomputing a run happens on every adjustment; if the month
         * read its own recovery as repayment the instalment would shrink each
         * time until it reached nought. Excluding rather than adding back also
         * stays correct once the period is approved, where the recovery counts
         * in both figures and adding it back would double it.
         */
        $available = (float) $this->principal - $this->recoveredExcluding($period);

        return round(max(0, min($instalment, $available)), 2);
    }

    public function isSettled(): bool
    {
        return $this->principal !== null && $this->outstanding() <= 0;
    }
}
