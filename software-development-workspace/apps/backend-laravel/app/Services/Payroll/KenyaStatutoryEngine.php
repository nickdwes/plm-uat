<?php

namespace App\Services\Payroll;

/**
 * Kenyan statutory payroll arithmetic.
 *
 * ── What changed, and why it mattered ──
 *
 * Taxable pay used to be `gross − NSSF`. Since the Tax Laws (Amendment) Act
 * 2024, SHIF and the Affordable Housing Levy are allowable deductions before
 * PAYE, alongside pension contributions, the post-retirement medical fund and
 * mortgage interest. Leaving them out overstates taxable pay, and therefore
 * over-deducts tax from every single employee: at KES 200,000 gross the base
 * was 197,840 where the law says 189,340, and the 8,500 difference is taxed at
 * 30% — about KES 2,550 a month taken off somebody who was owed it.
 *
 * ── Who pays NITA ──
 *
 * The industrial training levy is KES 50 per employee per month and it is the
 * *employer's* to pay. It used to sit inside the employee's deductions, so
 * every net pay was fifty shillings light. It is now an employer cost by
 * default, and `nita_borne_by` exists only because a business may already be
 * doing something else and a silent change of somebody's take-home is worse
 * than a configurable one.
 *
 * ── Everything is a rate somebody can change ──
 *
 * Nothing here is a number this file invents. The constants are the position as
 * at the time of writing, used only when an organisation has configured
 * nothing; the rates in force come from `payroll_config_statutory_rates` and
 * are selected for the date the period covers, not the date the run happens.
 */
class KenyaStatutoryEngine
{
    public const PERSONAL_RELIEF       = 2400.00;
    public const INSURANCE_RELIEF_MAX  = 5000.00;
    public const INSURANCE_RELIEF_RATE = 0.15;
    public const SHIF_RATE             = 0.0275;

    /*
     * NSSF Act 2013, the limits in force from February 2025.
     *
     * Tier I is 6% of pensionable pay to the lower limit; Tier II is 6% of the
     * slice between the lower and upper limits. The seeded values were 7,000
     * and 36,000 — the year-two figures, two years out of date, which
     * under-deducted by half and quietly understated the pension somebody is
     * building.
     */
    public const NSSF_TIER1_CEILING    = 8000.00;
    public const NSSF_TIER2_CEILING    = 72000.00;
    public const NSSF_RATE             = 0.06;

    public const HOUSING_LEVY_RATE     = 0.015;
    public const NITA                  = 50.00;

    /** Monthly caps on what may be set against income before PAYE. */
    public const PENSION_RELIEF_MAX          = 30000.00;
    public const POST_RETIREMENT_MEDICAL_MAX = 15000.00;
    public const MORTGAGE_INTEREST_MAX       = 30000.00;


    private const PAYE_BANDS = [
        ['limit' => 24000,      'rate' => 0.10],
        ['limit' => 32333,      'rate' => 0.25],
        ['limit' => 500000,     'rate' => 0.30],
        ['limit' => 800000,     'rate' => 0.325],
        ['limit' => PHP_INT_MAX,'rate' => 0.35],
    ];

    /**
     * One month's statutory position for one person.
     *
     * @param  array $config    Rates in force for the period being run.
     * @param  array $allowable pension_contribution, post_retirement_medical,
     *                          mortgage_interest — each capped, each optional.
     */
    public static function compute(
        float $grossPay,
        float $insurancePremiums = 0.0,
        array $config = [],
        array $allowable = [],
    ): array {
        $personalRelief  = (float) ($config['personal_relief']      ?? self::PERSONAL_RELIEF);
        $insReliefRate   = ((float) ($config['insurance_relief_rate'] ?? (self::INSURANCE_RELIEF_RATE * 100))) / 100;
        $insReliefMax    = (float) ($config['insurance_relief_max']  ?? self::INSURANCE_RELIEF_MAX);
        $shifRate        = ((float) ($config['shif_rate']            ?? (self::SHIF_RATE * 100))) / 100;
        $shifMin         = (float) ($config['shif_min']              ?? 0);
        $shifMax         = (float) ($config['shif_max']              ?? 0);
        $tier1Ceiling    = (float) ($config['nssf_tier1_ceiling']    ?? self::NSSF_TIER1_CEILING);
        $tier2Ceiling    = (float) ($config['nssf_tier2_ceiling']    ?? self::NSSF_TIER2_CEILING);
        $nssfRate        = ((float) ($config['nssf_employee_rate']   ?? (self::NSSF_RATE * 100))) / 100;
        $nssfEmployer    = ((float) ($config['nssf_employer_rate']   ?? (self::NSSF_RATE * 100))) / 100;
        $levyRate        = ((float) ($config['housing_levy_employee_rate'] ?? (self::HOUSING_LEVY_RATE * 100))) / 100;
        $levyEmployer    = ((float) ($config['housing_levy_employer_rate'] ?? (self::HOUSING_LEVY_RATE * 100))) / 100;
        $nitaAmt         = (float) ($config['nita_amount']           ?? self::NITA);
        // 1 takes it off the employee; anything else leaves it with the employer.
        $nitaOnEmployee  = (int) ($config['nita_on_employee'] ?? 0) === 1;
        $taxBands        = $config['tax_bands']                      ?? null;

        // ── Contributions, all computed on gross ──────────────────────────
        $nssfTier1 = round(min($grossPay, $tier1Ceiling) * $nssfRate, 2);
        $nssfTier2 = $grossPay > $tier1Ceiling
            ? round((min($grossPay, $tier2Ceiling) - $tier1Ceiling) * $nssfRate, 2)
            : 0.0;
        $nssfTotal = round($nssfTier1 + $nssfTier2, 2);

        $shif = round($grossPay * $shifRate, 2);
        if ($shifMin > 0) { $shif = max($shif, $shifMin); }
        if ($shifMax > 0) { $shif = min($shif, $shifMax); }

        $housingLevy = round($grossPay * $levyRate, 2);

        // ── What may be set against income before tax ─────────────────────
        $pension = min(
            (float) ($allowable['pension_contribution'] ?? 0),
            (float) ($config['pension_relief_max'] ?? self::PENSION_RELIEF_MAX),
        );
        $medical = min(
            (float) ($allowable['post_retirement_medical'] ?? 0),
            (float) ($config['post_retirement_medical_max'] ?? self::POST_RETIREMENT_MEDICAL_MAX),
        );
        $mortgage = min(
            (float) ($allowable['mortgage_interest'] ?? 0),
            (float) ($config['mortgage_interest_max'] ?? self::MORTGAGE_INTEREST_MAX),
        );

        /*
         * SHIF and the housing levy belong here, and their absence was the bug
         * this class was rewritten for. NSSF was already allowed; the other two
         * became allowable in December 2024 and nothing was changed to match.
         */
        $allowableTotal = round($nssfTotal + $shif + $housingLevy + $pension + $medical + $mortgage, 2);
        $taxableIncome  = round(max(0, $grossPay - $allowableTotal), 2);

        $payeGross = $taxBands
            ? self::computePayeFromBands($taxableIncome, $taxBands)
            : self::computePaye($taxableIncome);

        $insuranceRelief = min($insurancePremiums * $insReliefRate, $insReliefMax);
        $payePayable     = max(0, $payeGross - $personalRelief - $insuranceRelief);

        // ── What actually comes off the payslip ───────────────────────────
        $nitaFromEmployee = $nitaOnEmployee ? $nitaAmt : 0.0;

        $employeeDeductions = round(
            $payePayable + $shif + $nssfTotal + $housingLevy + $nitaFromEmployee,
            2,
        );

        $netPay = round(max(0, $grossPay - $employeeDeductions), 2);

        /*
         * What the employee costs beyond their pay. Not on the payslip, but it
         * is what the statutory returns are filed on and what a budget needs.
         */
        $employerNssf  = round($nssfTotal > 0 ? (min($grossPay, $tier2Ceiling) * $nssfEmployer) : 0, 2);
        $employerLevy  = round($grossPay * $levyEmployer, 2);
        $employerNita  = $nitaOnEmployee ? 0.0 : $nitaAmt;

        return [
            'gross_pay'         => round($grossPay, 2),

            'nssf_tier1'        => $nssfTier1,
            'nssf_tier2'        => $nssfTier2,
            'nssf_total'        => $nssfTotal,
            'shif'              => $shif,
            'housing_levy'      => $housingLevy,

            'pension_relief'         => round($pension, 2),
            'post_retirement_medical'=> round($medical, 2),
            'mortgage_interest'      => round($mortgage, 2),
            'allowable_deductions'   => $allowableTotal,
            'taxable_income'         => $taxableIncome,

            'paye_gross'        => round($payeGross, 2),
            // Kept as an alias: `paye` has meant gross-of-relief PAYE since the
            // first version and the P9 report reads it.
            'paye'              => round($payeGross, 2),
            'personal_relief'   => round($personalRelief, 2),
            'insurance_relief'  => round($insuranceRelief, 2),
            'paye_payable'      => round($payePayable, 2),

            'nita'              => round($nitaAmt, 2),
            'nita_on_employee'  => $nitaOnEmployee,

            'employee_deductions' => $employeeDeductions,
            // Retained under its old name; it is what the payslip totals.
            'total_statutory'   => $employeeDeductions,
            'net_pay'           => $netPay,

            'employer_nssf'         => $employerNssf,
            'employer_housing_levy' => $employerLevy,
            'employer_nita'         => round($employerNita, 2),
            'employer_total'        => round($employerNssf + $employerLevy + $employerNita, 2),
            'cost_to_company'       => round($grossPay + $employerNssf + $employerLevy + $employerNita, 2),
        ];
    }

    private static function computePaye(float $taxableIncome): float
    {
        $tax = 0.0;
        $remaining = $taxableIncome;
        $prevLimit = 0;

        foreach (self::PAYE_BANDS as $band) {
            if ($remaining <= 0) {
                break;
            }

            $width = ($band['limit'] === PHP_INT_MAX)
                ? $remaining
                : min($remaining, $band['limit'] - $prevLimit);

            $tax += $width * $band['rate'];
            $remaining -= $width;
            $prevLimit = $band['limit'];
        }

        return round($tax, 2);
    }

    private static function computePayeFromBands(float $taxableIncome, array $bands): float
    {
        $tax = 0.0;
        usort($bands, fn ($a, $b) => $a['lower_limit'] <=> $b['lower_limit']);

        foreach ($bands as $band) {
            $from = (float) $band['lower_limit'];
            $to   = isset($band['upper_limit']) && $band['upper_limit'] !== null
                ? (float) $band['upper_limit']
                : PHP_INT_MAX;
            $rate = (float) $band['rate'] / 100;

            if ($taxableIncome <= $from) {
                break;
            }

            $inBand = min($taxableIncome, $to) - $from;

            if ($inBand > 0) {
                $tax += $inBand * $rate;
            }
        }

        return round($tax, 2);
    }
}
