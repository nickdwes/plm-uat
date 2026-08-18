<?php

namespace App\Services\Payroll;

use App\Models\EmployeeRecurringDeduction;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Models\PayrollRunItem;

/**
 * Turning standing orders into this month's lines.
 *
 * Runs as part of computing a run, before the totals are struck, so a SACCO
 * check-off appears on the payslip the same way an ad-hoc deduction does and
 * needs no separate machinery to be seen, sent or explained.
 *
 * ── Written, not derived ──
 *
 * Each recovery becomes a real run item rather than a figure conjured at
 * display time. That is what makes the repayment history the sum of what
 * actually came off somebody's pay, rather than a number kept alongside it that
 * disagrees the first time a run is recomputed.
 *
 * ── The two-thirds rule ──
 *
 * Employment Act 2007 s.19(3): total deductions may not exceed two-thirds of
 * wages. When they would, recoveries are trimmed by priority — a court order
 * before a welfare contribution — and the shortfall is *recorded on the line*.
 * Silently under-recovering is worse than not recovering: the loan looks on
 * schedule and is not.
 */
class RecurringDeductionApplier
{
    /**
     * @param  float $statutoryDeductions PAYE, SHIF, NSSF, housing levy
     * @param  float $otherDeductions     ad-hoc lines already on the run
     * @return float total recovered this period
     */
    public function apply(
        PayrollRun $run,
        PayrollPeriod $period,
        float $grossPay,
        float $statutoryDeductions,
        float $otherDeductions,
    ): float {
        $schedules = EmployeeRecurringDeduction::withoutGlobalScope('organization')
            ->where('employee_id', $run->employee_id)
            ->where('organization_id', $period->organization_id)
            ->where('status', EmployeeRecurringDeduction::STATUS_ACTIVE)
            ->with('code')
            ->get()
            // Lowest number first: a court order at 8 comes off before welfare
            // at 13, which is the order that matters when the money runs out.
            ->sortBy(fn ($s) => [$s->priority, $s->id])
            ->values();

        /*
         * What is left before the floor is breached. Statutory deductions and
         * ad-hoc lines are already committed, so the room available to standing
         * orders is whatever remains between them and two-thirds of gross.
         */
        $ceiling = round($grossPay * 2 / 3, 2);
        $room    = round($ceiling - $statutoryDeductions - $otherDeductions, 2);

        $total = 0.0;
        $seen  = [];

        foreach ($schedules as $schedule) {
            $due = $schedule->dueFor($period);

            if ($due <= 0) {
                // A loan that has finished paying itself off says so, rather
                // than sitting active and recovering nought every month.
                if ($schedule->isSettled()) {
                    $schedule->update(['status' => EmployeeRecurringDeduction::STATUS_COMPLETED]);
                }

                $this->forget($run, $schedule);
                continue;
            }

            $taken   = round(min($due, max(0, $room)), 2);
            $shortfall = round($due - $taken, 2);

            if ($taken <= 0) {
                $this->forget($run, $schedule);
                continue;
            }

            $room  = round($room - $taken, 2);
            $total = round($total + $taken, 2);

            // updateOrCreate on the schedule, not create: a run is recomputed
            // on every adjustment, and a second row would recover twice.
            PayrollRunItem::updateOrCreate(
                [
                    'payroll_run_id' => $run->id,
                    'employee_recurring_deduction_id' => $schedule->id,
                ],
                [
                    'type'   => PayrollRunItem::TYPE_DEDUCTION,
                    'label'  => $schedule->label,
                    'amount' => $taken,
                    'payroll_deduction_code_id' => $schedule->payroll_deduction_code_id,
                    'is_taxable' => true,
                    'note'   => $this->note($schedule, $shortfall),
                ],
            );

            $seen[] = $schedule->id;
        }

        /*
         * Anything this run still carries from a schedule that is no longer
         * active — suspended, completed, deleted — goes. A stale line is how
         * somebody keeps being recovered for a loan they finished paying, and
         * it would never be reached by the loop above because the schedule is
         * no longer in it.
         */
        PayrollRunItem::where('payroll_run_id', $run->id)
            ->whereNotNull('employee_recurring_deduction_id')
            ->whereNotIn('employee_recurring_deduction_id', $seen ?: [0])
            ->delete();

        return $total;
    }

    /**
     * Why this line is not the full instalment.
     *
     * Put on the payslip rather than in a log. The person reading it is the one
     * whose loan is now a month longer, and "Sacco Loan 4,000" where they
     * expected 12,000 is a question somebody has to answer either way.
     */
    private function note(EmployeeRecurringDeduction $schedule, float $shortfall): ?string
    {
        if ($shortfall > 0.005) {
            return sprintf(
                'Reduced by %s — deductions may not exceed two thirds of pay',
                number_format($shortfall, 2),
            );
        }

        $outstanding = $schedule->outstanding();

        if ($outstanding !== null && $outstanding <= (float) $schedule->instalment) {
            return 'Final instalment';
        }

        return $schedule->reference ? "Ref {$schedule->reference}" : null;
    }

    /**
     * Drop a line that should no longer be there.
     *
     * A schedule suspended, settled or squeezed out by the two-thirds rule must
     * take its line with it. Leaving a stale row is how somebody keeps being
     * recovered for a loan they finished paying.
     */
    private function forget(PayrollRun $run, EmployeeRecurringDeduction $schedule): void
    {
        PayrollRunItem::where('payroll_run_id', $run->id)
            ->where('employee_recurring_deduction_id', $schedule->id)
            ->delete();
    }
}
