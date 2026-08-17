<?php

namespace Tests\Unit\Payroll;

use App\Services\Payroll\KenyaStatutoryEngine as Engine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The arithmetic that decides what people are paid.
 *
 * It had no tests at all. Seven payroll tests existed and every one of them was
 * about who may see a period, not about whether the numbers on it are right —
 * which is the one thing in this module that must never be wrong.
 *
 * The figures below are worked by hand from the rates in the class, so a change
 * to the law is a visible change to a test rather than a silent change to
 * somebody's pay.
 */
class KenyaStatutoryEngineTest extends TestCase
{
    // ── NSSF ──────────────────────────────────────────────────────────────────

    /**
     * Tier I is 6% to the lower limit; Tier II is 6% of the slice up to the
     * upper one. Both limits moved in February 2025 and the seeded config still
     * held the year-two figures.
     */
    public static function nssfProvider(): array
    {
        return [
            // gross,   tier 1,  tier 2,  why
            'below the lower limit' => [5000.0,   300.00,    0.00],
            'at the lower limit'    => [8000.0,   480.00,    0.00],
            'between the limits'    => [30000.0,  480.00, 1320.00],
            'at the upper limit'    => [72000.0,  480.00, 3840.00],
            'above the upper limit' => [200000.0, 480.00, 3840.00],
        ];
    }

    #[DataProvider('nssfProvider')]
    public function test_nssf_is_charged_in_two_tiers(float $gross, float $tier1, float $tier2): void
    {
        $r = Engine::compute($gross);

        $this->assertSame($tier1, $r['nssf_tier1']);
        $this->assertSame($tier2, $r['nssf_tier2']);
        $this->assertSame(round($tier1 + $tier2, 2), $r['nssf_total']);
    }

    /** Nobody contributes more than 6% of the upper limit. */
    public function test_nssf_is_capped(): void
    {
        $this->assertSame(4320.00, Engine::compute(1_000_000.0)['nssf_total']);
    }

    // ── The taxable base ──────────────────────────────────────────────────────

    /**
     * The bug this suite exists for.
     *
     * SHIF and the housing levy are allowable before PAYE. At 200,000 gross the
     * base was 197,840 where the law says 189,340 — and the 8,500 difference is
     * taxed at 30%, so about 2,550 a month was being taken off everybody.
     */
    public function test_shif_and_housing_levy_reduce_taxable_pay(): void
    {
        $r = Engine::compute(200_000.0);

        $this->assertSame(5500.00, $r['shif']);          // 2.75%
        $this->assertSame(3000.00, $r['housing_levy']);  // 1.5%
        $this->assertSame(4320.00, $r['nssf_total']);

        // 200,000 − 4,320 − 5,500 − 3,000
        $this->assertSame(12820.00, $r['allowable_deductions']);
        $this->assertSame(187180.00, $r['taxable_income']);
    }

    public function test_pension_medical_and_mortgage_are_allowable_within_their_caps(): void
    {
        $r = Engine::compute(200_000.0, 0.0, [], [
            'pension_contribution'    => 50_000.0,   // capped at 30,000
            'post_retirement_medical' => 20_000.0,   // capped at 15,000
            'mortgage_interest'       => 10_000.0,   // under the cap, taken whole
        ]);

        $this->assertSame(30000.00, $r['pension_relief']);
        $this->assertSame(15000.00, $r['post_retirement_medical']);
        $this->assertSame(10000.00, $r['mortgage_interest']);

        // 12,820 of contributions plus 55,000 of reliefs.
        $this->assertSame(67820.00, $r['allowable_deductions']);
        $this->assertSame(132180.00, $r['taxable_income']);
    }

    public function test_taxable_pay_never_goes_below_nought(): void
    {
        $r = Engine::compute(5_000.0, 0.0, [], ['mortgage_interest' => 30_000.0]);

        $this->assertSame(0.00, $r['taxable_income']);
        $this->assertSame(0.00, $r['paye_payable']);
    }

    // ── PAYE ──────────────────────────────────────────────────────────────────

    /**
     * Banded at 10, 25, 30, 32.5 and 35 per cent, then personal relief comes
     * off the tax rather than the income.
     */
    public function test_paye_is_banded_and_relief_comes_off_the_tax(): void
    {
        $r = Engine::compute(50_000.0);

        // NSSF 3,000 (480 + 2,520), SHIF 1,375, levy 750 → 50,000 − 5,125
        $this->assertSame(3000.00, $r['nssf_total']);
        $this->assertSame(44875.00, $r['taxable_income']);

        // 24,000 @ 10% = 2,400; 8,333 @ 25% = 2,083.25; 12,542 @ 30% = 3,762.60
        $this->assertSame(8245.85, $r['paye_gross']);
        $this->assertSame(2400.00, $r['personal_relief']);
        $this->assertSame(5845.85, $r['paye_payable']);
    }

    /** Below the relief threshold nobody pays tax, and it never goes negative. */
    public function test_a_small_salary_pays_no_paye(): void
    {
        $r = Engine::compute(20_000.0);

        $this->assertGreaterThan(0, $r['paye_gross']);
        $this->assertSame(0.00, $r['paye_payable']);
        $this->assertSame(20_000.00 - $r['employee_deductions'], $r['net_pay']);
    }

    /** 15% of premiums, capped at 5,000, taken off the tax with personal relief. */
    public function test_insurance_relief_is_fifteen_per_cent_of_premiums(): void
    {
        $modest = Engine::compute(200_000.0, 10_000.0);
        $this->assertSame(1500.00, $modest['insurance_relief']);

        $large = Engine::compute(200_000.0, 100_000.0);
        $this->assertSame(5000.00, $large['insurance_relief']);
    }

    // ── Who pays what ─────────────────────────────────────────────────────────

    /**
     * The training levy is the employer's. It used to sit in the employee's
     * deductions, so every net pay was fifty shillings light.
     */
    public function test_nita_does_not_come_off_the_employee_by_default(): void
    {
        $r = Engine::compute(100_000.0);

        $this->assertSame(50.00, $r['nita']);
        $this->assertFalse($r['nita_on_employee']);
        $this->assertSame(50.00, $r['employer_nita']);

        $expected = round($r['paye_payable'] + $r['shif'] + $r['nssf_total'] + $r['housing_levy'], 2);
        $this->assertSame($expected, $r['employee_deductions']);
    }

    public function test_nita_can_be_put_on_the_employee_where_that_is_the_practice(): void
    {
        $default = Engine::compute(100_000.0);
        $onStaff = Engine::compute(100_000.0, 0.0, ['nita_on_employee' => 1]);

        $this->assertTrue($onStaff['nita_on_employee']);
        $this->assertSame(0.00, $onStaff['employer_nita']);
        $this->assertSame(round($default['net_pay'] - 50, 2), $onStaff['net_pay']);
    }

    /** What the person costs beyond their pay, which the returns are filed on. */
    public function test_employer_contributions_are_reported_separately(): void
    {
        $r = Engine::compute(100_000.0);

        $this->assertSame(4320.00, $r['employer_nssf']);      // 6% of the 72,000 limit
        $this->assertSame(1500.00, $r['employer_housing_levy']);
        $this->assertSame(50.00, $r['employer_nita']);
        $this->assertSame(5870.00, $r['employer_total']);
        $this->assertSame(105870.00, $r['cost_to_company']);
    }

    // ── The whole payslip ─────────────────────────────────────────────────────

    /** Everything taken off is accounted for, and net is what is left. */
    public function test_the_payslip_adds_up(): void
    {
        foreach ([15_000.0, 50_000.0, 200_000.0, 900_000.0] as $gross) {
            $r = Engine::compute($gross);

            $this->assertSame(
                round($r['paye_payable'] + $r['shif'] + $r['nssf_total'] + $r['housing_levy'], 2),
                $r['employee_deductions'],
                "deductions do not add up at {$gross}",
            );

            $this->assertSame(
                round($gross - $r['employee_deductions'], 2),
                $r['net_pay'],
                "net pay does not reconcile at {$gross}",
            );

            $this->assertLessThan($gross, $r['net_pay'], "net exceeds gross at {$gross}");
        }
    }

    /** Configured rates win over the defaults in this file. */
    public function test_configured_rates_override_the_defaults(): void
    {
        $r = Engine::compute(100_000.0, 0.0, [
            'shif_rate'                  => 3.0,
            'housing_levy_employee_rate' => 2.0,
            'personal_relief'            => 3000,
        ]);

        $this->assertSame(3000.00, $r['shif']);
        $this->assertSame(2000.00, $r['housing_levy']);
        $this->assertSame(3000.00, $r['personal_relief']);
    }

    /** A SHIF floor and ceiling, where an organisation sets them. */
    public function test_shif_respects_a_configured_floor_and_ceiling(): void
    {
        $floored = Engine::compute(10_000.0, 0.0, ['shif_min' => 300]);
        $this->assertSame(300.00, $floored['shif']);

        $capped = Engine::compute(500_000.0, 0.0, ['shif_max' => 5000]);
        $this->assertSame(5000.00, $capped['shif']);
    }

    /** Bands from configuration are used in place of the built-in ones. */
    public function test_configured_tax_bands_are_used(): void
    {
        $r = Engine::compute(100_000.0, 0.0, [
            'tax_bands' => [
                ['lower_limit' => 0,      'upper_limit' => 50_000, 'rate' => 10],
                ['lower_limit' => 50_000, 'upper_limit' => null,   'rate' => 20],
            ],
            'personal_relief' => 0,
        ]);

        // 100,000 − 4,320 − 2,750 − 1,500 = 91,430 taxable
        $this->assertSame(91430.00, $r['taxable_income']);
        // 50,000 @ 10% + 41,430 @ 20%
        $this->assertSame(13286.00, $r['paye_gross']);
    }
}
