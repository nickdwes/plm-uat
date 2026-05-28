<?php

namespace App\Services\Payroll;

/**
 * Kenya Statutory Deductions Engine — FY 2025/2026
 *
 * Implements:
 *  - PAYE (Pay As You Earn) — KRA 2025 tax bands
 *  - Personal Relief — KES 2,400 / month
 *  - Insurance Relief — 15% of premiums, max KES 5,000 / month
 *  - SHIF (Social Health Insurance Fund) — 2.75% of gross, no ceiling
 *  - NSSF Tier I & II (NSSF Act 2013) — employer & employee each
 *  - Affordable Housing Levy — 1.5% employee, 1.5% employer
 *  - NITA levy — KES 50 / month per employee
 *
 * All amounts in KES.  Figures are MONTHLY.
 */
class KenyaStatutoryEngine
{
    // ── Constants ─────────────────────────────────────────────────────────────

    /** Monthly personal relief (KES) */
    public const PERSONAL_RELIEF = 2_400.00;

    /** Insurance relief cap per month (KES) */
    public const INSURANCE_RELIEF_MAX = 5_000.00;

    /** Insurance relief rate */
    public const INSURANCE_RELIEF_RATE = 0.15;

    /** SHIF rate (2.75 % of gross) */
    public const SHIF_RATE = 0.0275;

    /** NSSF upper limit for Tier I (KES) */
    public const NSSF_TIER1_CEILING = 7_000.00;

    /** NSSF Tier II band: from 7,001 to 36,000 (KES) */
    public const NSSF_TIER2_CEILING = 36_000.00;

    /** NSSF employee contribution rate (6 %) */
    public const NSSF_RATE = 0.06;

    /** Affordable Housing Levy employee rate (1.5 %) */
    public const HOUSING_LEVY_RATE = 0.015;

    /** NITA levy flat amount per month per employee (KES) */
    public const NITA = 50.00;

    // ── PAYE Tax Bands (monthly, KES) — effective Jan 2025 ───────────────────
    private const PAYE_BANDS = [
        ['limit' => 24_000,  'rate' => 0.10],
        ['limit' => 32_333,  'rate' => 0.25],
        ['limit' => PHP_INT_MAX, 'rate' => 0.30],
    ];

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Compute all Kenya statutory deductions for a single employee for one month.
     *
     * @param  float  $grossPay          Gross monthly pay (basic + allowances + ad-hoc earnings)
     * @param  float  $insurancePremiums Monthly insurance premiums paid by employee
     * @return array{
     *     gross_pay: float,
     *     paye_gross: float,
     *     paye: float,
     *     personal_relief: float,
     *     insurance_relief: float,
     *     paye_payable: float,
     *     shif: float,
     *     nssf_tier1: float,
     *     nssf_tier2: float,
     *     nssf_total: float,
     *     housing_levy: float,
     *     nita: float,
     *     total_statutory: float,
     *     net_pay: float,
     * }
     */
    public static function compute(float $grossPay, float $insurancePremiums = 0.0): array
    {
        // ── NSSF (computed first — NSSF is deductible for PAYE purposes) ─────
        $nssfTier1 = self::computeNssfTier1($grossPay);
        $nssfTier2 = self::computeNssfTier2($grossPay);
        $nssfTotal = $nssfTier1 + $nssfTier2;

        // ── Taxable income = gross − NSSF employee contribution ──────────────
        $taxableIncome = max(0, $grossPay - $nssfTotal);

        // ── PAYE (before reliefs) ─────────────────────────────────────────────
        $payeGross = self::computePaye($taxableIncome);

        // ── Reliefs ───────────────────────────────────────────────────────────
        $personalRelief   = self::PERSONAL_RELIEF;
        $insuranceRelief  = min(
            $insurancePremiums * self::INSURANCE_RELIEF_RATE,
            self::INSURANCE_RELIEF_MAX
        );

        // ── PAYE payable (cannot go below zero) ───────────────────────────────
        $payePayable = max(0, $payeGross - $personalRelief - $insuranceRelief);

        // ── SHIF ──────────────────────────────────────────────────────────────
        $shif = round($grossPay * self::SHIF_RATE, 2);

        // ── Housing Levy ──────────────────────────────────────────────────────
        $housingLevy = round($grossPay * self::HOUSING_LEVY_RATE, 2);

        // ── NITA ─────────────────────────────────────────────────────────────
        $nita = self::NITA;

        // ── Totals ────────────────────────────────────────────────────────────
        $totalStatutory = $payePayable + $shif + $nssfTotal + $housingLevy + $nita;
        $netPay         = max(0, $grossPay - $totalStatutory);

        return [
            'gross_pay'        => round($grossPay, 2),
            'paye_gross'       => round($payeGross, 2),
            'paye'             => round($payeGross, 2),
            'personal_relief'  => round($personalRelief, 2),
            'insurance_relief' => round($insuranceRelief, 2),
            'paye_payable'     => round($payePayable, 2),
            'shif'             => round($shif, 2),
            'nssf_tier1'       => round($nssfTier1, 2),
            'nssf_tier2'       => round($nssfTier2, 2),
            'nssf_total'       => round($nssfTotal, 2),
            'housing_levy'     => round($housingLevy, 2),
            'nita'             => $nita,
            'total_statutory'  => round($totalStatutory, 2),
            'net_pay'          => round($netPay, 2),
        ];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Progressive PAYE on taxable income using 2025 monthly bands.
     */
    private static function computePaye(float $taxableIncome): float
    {
        $tax       = 0.0;
        $remaining = $taxableIncome;
        $prevLimit = 0;

        foreach (self::PAYE_BANDS as $band) {
            if ($remaining <= 0) break;

            $bandWidth = $band['limit'] === PHP_INT_MAX
                ? $remaining
                : min($remaining, $band['limit'] - $prevLimit);

            $tax      += $bandWidth * $band['rate'];
            $remaining -= $bandWidth;
            $prevLimit  = $band['limit'];
        }

        return round($tax, 2);
    }

    private static function computeNssfTier1(float $gross): float
    {
        return round(min($gross, self::NSSF_TIER1_CEILING) * self::NSSF_RATE, 2);
    }

    private static function computeNssfTier2(float $gross): float
    {
        if ($gross <= self::NSSF_TIER1_CEILING) return 0.0;
        $tier2Base = min($gross, self::NSSF_TIER2_CEILING) - self::NSSF_TIER1_CEILING;
        return round($tier2Base * self::NSSF_RATE, 2);
    }
}
