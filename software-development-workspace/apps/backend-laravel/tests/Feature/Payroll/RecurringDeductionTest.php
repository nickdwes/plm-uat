<?php

namespace Tests\Feature\Payroll;

use App\Models\Employee;
use App\Models\EmployeeRecurringDeduction;
use App\Models\Organization;
use App\Models\PayrollDeductionCode;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Models\PayrollRunItem;
use App\Services\Payroll\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Standing orders against pay: a SACCO check-off, a HELB repayment, an advance.
 *
 * The failure modes here all cost somebody real money and none of them are
 * loud: recovering twice because a run was recomputed, recovering past the
 * principal because nothing counted, or quietly recovering nothing because a
 * figure drifted. Each has a test.
 */
class RecurringDeductionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Employee $employee;
    private PayrollDeductionCode $sacco;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);

        $this->employee = Employee::create([
            'employee_number'         => 'EMP-0001',
            'primary_organization_id' => $this->org->id,
            'first_name' => 'Grace', 'last_name' => 'Njoroge',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
            'status' => 'active', 'national_id' => '12345678',
            'basic_salary' => 100000,
        ]);

        $this->sacco = PayrollDeductionCode::create([
            'organization_id' => $this->org->id,
            'code' => 'SACCO', 'name' => 'Sacco Loan',
            'deduction_type' => 'checkoff', 'priority' => 11, 'is_active' => true,
        ]);
    }

    private function service(): PayrollService
    {
        return app(PayrollService::class);
    }

    private function period(string $name, string $start, string $end): PayrollPeriod
    {
        return PayrollPeriod::create([
            'organization_id' => $this->org->id,
            'name' => $name, 'start_date' => $start, 'end_date' => $end,
            'payment_date' => $end, 'status' => PayrollPeriod::STATUS_DRAFT,
        ]);
    }

    private function schedule(array $attrs = []): EmployeeRecurringDeduction
    {
        return EmployeeRecurringDeduction::create(array_merge([
            'organization_id' => $this->org->id,
            'employee_id'     => $this->employee->id,
            'payroll_deduction_code_id' => $this->sacco->id,
            'label'      => 'Sacco Loan',
            'instalment' => 12000,
            'principal'  => 50000,
            'starts_on'  => '2026-01-01',
            'priority'   => 11,
        ], $attrs));
    }

    private function runFor(PayrollPeriod $period): PayrollRun
    {
        $this->service()->processPeriod($period->id);

        return PayrollRun::where('payroll_period_id', $period->id)
            ->where('employee_id', $this->employee->id)->firstOrFail();
    }

    // ── It comes off at all ───────────────────────────────────────────────────

    public function test_a_standing_order_is_recovered_without_anybody_typing_it(): void
    {
        $this->schedule();

        $run = $this->runFor($this->period('Jan', '2026-01-01', '2026-01-31'));

        $this->assertSame(12000.0, round((float) $run->recurring_deductions, 2));

        $line = PayrollRunItem::where('payroll_run_id', $run->id)->first();
        $this->assertSame('Sacco Loan', $line->label);
        $this->assertSame(PayrollRunItem::TYPE_DEDUCTION, $line->type);
    }

    /** It comes off net pay, not off the tax base. */
    public function test_it_does_not_reduce_taxable_pay(): void
    {
        $before = $this->runFor($this->period('Jan', '2026-01-01', '2026-01-31'));
        $grossBefore = (float) $before->gross_pay;
        $payeBefore  = (float) $before->paye_payable;

        $this->schedule();
        $after = $this->service()->recomputeRun($before->payroll_period_id, $this->employee->id);

        $this->assertSame(round($grossBefore, 2), round((float) $after->gross_pay, 2));
        $this->assertSame(round($payeBefore, 2), round((float) $after->paye_payable, 2));
        $this->assertSame(round((float) $before->net_pay - 12000, 2), round((float) $after->net_pay, 2));
    }

    // ── Recomputing must not recover twice ────────────────────────────────────

    /**
     * The one that would be invisible. A run is recomputed on every adjustment,
     * so a recovery that appends rather than updates takes the instalment again
     * each time somebody touches the run.
     */
    public function test_recomputing_does_not_recover_twice(): void
    {
        $this->schedule();
        $period = $this->period('Jan', '2026-01-01', '2026-01-31');
        $run    = $this->runFor($period);

        $this->service()->recomputeRun($period->id, $this->employee->id);
        $this->service()->recomputeRun($period->id, $this->employee->id);

        $fresh = $run->fresh();

        $this->assertSame(1, PayrollRunItem::where('payroll_run_id', $run->id)
            ->whereNotNull('employee_recurring_deduction_id')->count());
        $this->assertSame(12000.0, round((float) $fresh->recurring_deductions, 2));
    }

    // ── It stops on its own ───────────────────────────────────────────────────

    /** A loan of 50,000 at 12,000 takes 2,000 in the fifth month, not 12,000. */
    public function test_the_final_instalment_is_the_remainder(): void
    {
        $this->schedule();

        $months = [
            ['Jan', '2026-01-01', '2026-01-31'],
            ['Feb', '2026-02-01', '2026-02-28'],
            ['Mar', '2026-03-01', '2026-03-31'],
            ['Apr', '2026-04-01', '2026-04-30'],
        ];

        foreach ($months as [$name, $start, $end]) {
            $period = $this->period($name, $start, $end);
            $this->runFor($period);
            $period->update(['status' => PayrollPeriod::STATUS_APPROVED]);
        }

        // Four months at 12,000 is 48,000 of a 50,000 loan.
        $may = $this->period('May', '2026-05-01', '2026-05-31');
        $run = $this->runFor($may);

        $this->assertSame(2000.0, round((float) $run->recurring_deductions, 2));
    }

    /**
     * Instalment sized to clear in one month *under the two-thirds cap* — a
     * 50,000 recovery against 100,000 of pay is trimmed by the cap first, which
     * would test the cap rather than the settling.
     */
    public function test_it_recovers_nothing_once_the_principal_is_met_and_says_so(): void
    {
        $schedule = $this->schedule(['instalment' => 10000, 'principal' => 10000]);

        $jan = $this->period('Jan', '2026-01-01', '2026-01-31');
        $this->runFor($jan);
        $jan->update(['status' => PayrollPeriod::STATUS_APPROVED]);

        $feb = $this->period('Feb', '2026-02-01', '2026-02-28');
        $run = $this->runFor($feb);

        $this->assertSame(0.0, round((float) $run->recurring_deductions, 2));
        // It says it is finished rather than sitting active taking nought.
        $this->assertSame(EmployeeRecurringDeduction::STATUS_COMPLETED, $schedule->fresh()->status);
    }

    /** A check-off with no principal runs until somebody stops it. */
    public function test_an_open_ended_check_off_keeps_running(): void
    {
        $this->schedule(['principal' => null, 'instalment' => 2000]);

        foreach ([['Jan', '2026-01-01', '2026-01-31'], ['Feb', '2026-02-01', '2026-02-28']] as [$n, $s, $e]) {
            $period = $this->period($n, $s, $e);
            $run    = $this->runFor($period);
            $period->update(['status' => PayrollPeriod::STATUS_APPROVED]);

            $this->assertSame(2000.0, round((float) $run->recurring_deductions, 2));
        }
    }

    // ── Dates and status ──────────────────────────────────────────────────────

    public function test_it_does_not_start_before_its_date(): void
    {
        $this->schedule(['starts_on' => '2026-06-01']);

        $run = $this->runFor($this->period('Jan', '2026-01-01', '2026-01-31'));

        $this->assertSame(0.0, round((float) $run->recurring_deductions, 2));
    }

    /** Suspending it must take its line with it, not leave a stale one behind. */
    public function test_suspending_it_removes_the_line(): void
    {
        $schedule = $this->schedule();
        $period   = $this->period('Jan', '2026-01-01', '2026-01-31');
        $run      = $this->runFor($period);

        $this->assertSame(12000.0, round((float) $run->recurring_deductions, 2));

        $schedule->update(['status' => EmployeeRecurringDeduction::STATUS_SUSPENDED]);
        $after = $this->service()->recomputeRun($period->id, $this->employee->id);

        $this->assertSame(0.0, round((float) $after->recurring_deductions, 2));
        $this->assertSame(0, PayrollRunItem::where('payroll_run_id', $run->id)
            ->whereNotNull('employee_recurring_deduction_id')->count());
    }

    // ── The two-thirds rule ───────────────────────────────────────────────────

    /**
     * Employment Act 2007 s.19(3): deductions may not exceed two-thirds of
     * wages. A 90,000 instalment against 100,000 of pay cannot be taken.
     */
    public function test_deductions_are_capped_at_two_thirds_of_pay(): void
    {
        $this->schedule(['instalment' => 90000, 'principal' => 900000]);

        $run = $this->runFor($this->period('Jan', '2026-01-01', '2026-01-31'));

        $ceiling = round((float) $run->gross_pay * 2 / 3, 2);

        $this->assertLessThanOrEqual($ceiling + 0.01, (float) $run->total_deductions);
        $this->assertGreaterThan(0, (float) $run->recurring_deductions);
    }

    /** And the shortfall is written where the person affected will see it. */
    public function test_a_trimmed_recovery_says_why_on_the_payslip(): void
    {
        $this->schedule(['instalment' => 90000, 'principal' => 900000]);

        $run  = $this->runFor($this->period('Jan', '2026-01-01', '2026-01-31'));
        $line = PayrollRunItem::where('payroll_run_id', $run->id)
            ->whereNotNull('employee_recurring_deduction_id')->first();

        $this->assertStringContainsString('two thirds', $line->note);
    }

    /**
     * When the money runs out, the court order comes off before the welfare.
     *
     * Both ask for 40,000 and the two-thirds cap leaves room for well under
     * one of them, so this is the case that matters: the higher-priority
     * schedule takes the room and the lower one takes nothing, rather than both
     * being scaled down to something neither party agreed to.
     */
    public function test_priority_decides_who_is_paid_when_pay_runs_short(): void
    {
        $court = PayrollDeductionCode::create([
            'organization_id' => $this->org->id,
            'code' => 'COURT', 'name' => 'Court Order',
            'deduction_type' => 'statutory', 'priority' => 8, 'is_active' => true,
        ]);

        $this->schedule([
            'payroll_deduction_code_id' => $court->id, 'label' => 'Court Order',
            'instalment' => 40000, 'principal' => 400000, 'priority' => 8,
        ]);
        $this->schedule(['label' => 'Staff Welfare', 'instalment' => 40000, 'principal' => 400000, 'priority' => 13]);

        $run = $this->runFor($this->period('Jan', '2026-01-01', '2026-01-31'));

        $byLabel = PayrollRunItem::where('payroll_run_id', $run->id)
            ->whereNotNull('employee_recurring_deduction_id')->get()->keyBy('label');

        $court40 = (float) ($byLabel['Court Order']->amount ?? 0);
        $welfare = (float) ($byLabel['Staff Welfare']->amount ?? 0);

        $this->assertGreaterThan(0, $court40, 'the court order must be satisfied first');
        $this->assertSame(0.0, $welfare, 'the welfare contribution waits rather than part-paying');

        // Everything available went to the court order, and the floor holds.
        $ceiling = round((float) $run->gross_pay * 2 / 3, 2);
        $this->assertLessThanOrEqual($ceiling + 0.01, (float) $run->total_deductions);
        $this->assertStringContainsString('two thirds', (string) $byLabel['Court Order']->note);
    }

    // ── The balance is derived ────────────────────────────────────────────────

    /** Draft payroll still moves, so it must not count against a loan. */
    public function test_only_approved_payroll_counts_towards_the_balance(): void
    {
        $schedule = $this->schedule();

        $jan = $this->period('Jan', '2026-01-01', '2026-01-31');
        $this->runFor($jan);

        $this->assertSame(0.0, $schedule->fresh()->recovered());

        $jan->update(['status' => PayrollPeriod::STATUS_APPROVED]);

        $this->assertSame(12000.0, $schedule->fresh()->recovered());
        $this->assertSame(38000.0, $schedule->fresh()->outstanding());
    }
}
