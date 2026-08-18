<?php

namespace Tests\Feature\Payroll;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\P9Report;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Services\Payroll\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The P9 as a record, and as the form KRA actually wants.
 *
 * It was a query: "what would a P9 say today". The questions people ask are
 * whether this year's is finished, when it was last touched and whether it was
 * sent — none of which a query can answer.
 */
class P9ReportTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);

        $this->employee = Employee::create([
            'employee_number' => 'EMP-1', 'primary_organization_id' => $this->org->id,
            'first_name' => 'Grace', 'last_name' => 'Njoroge',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
        ]);
    }

    private function month(int $m, array $attrs = []): PayrollRun
    {
        $period = PayrollPeriod::create([
            'organization_id' => $this->org->id,
            'name'            => sprintf('2026-%02d', $m),
            'start_date'      => sprintf('2026-%02d-01', $m),
            'end_date'        => sprintf('2026-%02d-28', $m),
            'status'          => PayrollPeriod::STATUS_APPROVED,
        ]);

        return PayrollRun::create(array_merge([
            'payroll_period_id' => $period->id,
            'employee_id'       => $this->employee->id,
            'basic_salary' => 100000, 'house_allowance' => 20000, 'transport_allowance' => 10000,
            'other_earnings' => 0, 'gross_pay' => 130000,
            'taxable_income' => 121430, 'paye' => 30000, 'personal_relief' => 2400,
            'insurance_relief' => 0, 'paye_payable' => 27600,
            'shif' => 3575, 'nssf_tier1' => 480, 'nssf_tier2' => 3840,
            'housing_levy' => 1950, 'total_deductions' => 37445, 'net_pay' => 92555,
            'status' => 'approved',
        ], $attrs));
    }

    private function service(): PayrollService
    {
        return app(PayrollService::class);
    }

    // ── The record ────────────────────────────────────────────────────────────

    public function test_a_year_gets_a_record_with_a_reference_on_first_sight(): void
    {
        $record = P9Report::forYear($this->org->id, 2026);

        $this->assertSame('P9-2026-' . sprintf('%04d', $this->org->id), $record->reference);
        $this->assertSame(P9Report::STATUS_IN_PROGRESS, $record->status);
    }

    /** Two P9s for one year would be two answers to one question. */
    public function test_a_year_has_exactly_one_record(): void
    {
        $first  = P9Report::forYear($this->org->id, 2026);
        $second = P9Report::forYear($this->org->id, 2026);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, P9Report::withoutGlobalScope('organization')->count());
    }

    public function test_completing_records_who_and_when(): void
    {
        $record = P9Report::forYear($this->org->id, 2026);
        $record->update([
            'status' => P9Report::STATUS_COMPLETED,
            'completed_at' => now(),
            'completed_by' => $this->employee->id,
        ]);

        $this->assertTrue($record->fresh()->isCompleted());
        $this->assertNotNull($record->fresh()->completed_at);
    }

    // ── The form ──────────────────────────────────────────────────────────────

    /** Nobody files a sum. The P9A is a row per month. */
    public function test_the_year_is_returned_month_by_month(): void
    {
        $this->month(1);
        $this->month(2);
        $this->month(3);

        $months = $this->service()->getP9Months($this->org->id, 2026, $this->employee->id);

        $this->assertCount(3, $months);
        $this->assertSame(['January', 'February', 'March'], array_column($months, 'month'));
    }

    /** Column B is everything earned that is not basic pay. */
    public function test_benefits_group_the_allowances(): void
    {
        $this->month(1);

        $row = $this->service()->getP9Months($this->org->id, 2026, $this->employee->id)[0];

        $this->assertSame(100000.00, $row['basic_pay']);
        $this->assertSame(30000.00, $row['benefits']);   // 20,000 house + 10,000 transport
        $this->assertSame(130000.00, $row['gross_pay']);
    }

    /** E1 on the form: what was put aside, which is the NSSF pair. */
    public function test_the_defined_contribution_is_both_nssf_tiers(): void
    {
        $this->month(1);

        $row = $this->service()->getP9Months($this->org->id, 2026, $this->employee->id)[0];

        $this->assertSame(4320.00, $row['defined_contribution']);
    }

    /**
     * The figure the tax was charged on, read rather than recomputed — the P9
     * derived its own once and drifted from the PAYE beside it.
     */
    public function test_taxable_income_is_the_stored_figure(): void
    {
        $this->month(1);

        $row = $this->service()->getP9Months($this->org->id, 2026, $this->employee->id)[0];

        $this->assertSame(121430.00, $row['taxable_income']);
    }

    /** Only what has been approved. A draft is a working document. */
    public function test_draft_periods_are_not_on_the_p9(): void
    {
        $run = $this->month(1);
        $run->period->update(['status' => PayrollPeriod::STATUS_DRAFT]);

        $this->assertCount(0, $this->service()->getP9Months($this->org->id, 2026, $this->employee->id));
    }

    /** The annual figures still sum the months they came from. */
    public function test_the_annual_report_agrees_with_the_months(): void
    {
        $this->month(1);
        $this->month(2);

        $annual = $this->service()->getP9Report($this->org->id, 2026)[0];
        $months = $this->service()->getP9Months($this->org->id, 2026, $this->employee->id);

        $this->assertSame(2, $annual['months']);
        $this->assertSame(array_sum(array_column($months, 'gross_pay')), $annual['gross_pay']);
        $this->assertSame(array_sum(array_column($months, 'taxable_income')), $annual['taxable_income']);
        $this->assertSame(array_sum(array_column($months, 'paye_payable')), $annual['paye_payable']);
    }
}
