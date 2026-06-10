<?php

namespace App\Services\Payroll;

class KenyaStatutoryEngine
{
    public const PERSONAL_RELIEF      = 2400.00;
    public const INSURANCE_RELIEF_MAX  = 5000.00;
    public const INSURANCE_RELIEF_RATE = 0.15;
    public const SHIF_RATE             = 0.0275;
    public const NSSF_TIER1_CEILING    = 7000.00;
    public const NSSF_TIER2_CEILING    = 36000.00;
    public const NSSF_RATE             = 0.06;
    public const HOUSING_LEVY_RATE     = 0.015;
    public const NITA                  = 50.00;

    private const PAYE_BANDS = [
        ['limit' => 24000,      'rate' => 0.10],
        ['limit' => 32333,      'rate' => 0.25],
        ['limit' => 500000,     'rate' => 0.30],
        ['limit' => 800000,     'rate' => 0.325],
        ['limit' => PHP_INT_MAX,'rate' => 0.35],
    ];

    public static function compute(float $grossPay, float $insurancePremiums = 0.0, array $config = []): array
    {
        $personalRelief  = (float)($config['personal_relief']           ?? self::PERSONAL_RELIEF);
        $insReliefRate   = (($config['insurance_relief_rate']           ?? (self::INSURANCE_RELIEF_RATE * 100)) / 100);
        $insReliefMax    = (float)($config['insurance_relief_max']      ?? self::INSURANCE_RELIEF_MAX);
        $shifRate        = (($config['shif_rate']                       ?? (self::SHIF_RATE * 100)) / 100);
        $shifMin         = (float)($config['shif_min']                  ?? 0);
        $shifMax         = (float)($config['shif_max']                  ?? 0);
        $tier1Ceiling    = (float)($config['nssf_tier1_ceiling']        ?? self::NSSF_TIER1_CEILING);
        $tier2Ceiling    = (float)($config['nssf_tier2_ceiling']        ?? self::NSSF_TIER2_CEILING);
        $nssfRate        = (($config['nssf_employee_rate']              ?? (self::NSSF_RATE * 100)) / 100);
        $housingLevyRate = (($config['housing_levy_employee_rate']      ?? (self::HOUSING_LEVY_RATE * 100)) / 100);
        $nitaAmt         = (float)($config['nita_amount']               ?? self::NITA);
        $taxBands        = $config['tax_bands']                         ?? null;

        $nssfTier1 = round(min($grossPay, $tier1Ceiling) * $nssfRate, 2);
        $nssfTier2 = 0.0;
        if ($grossPay > $tier1Ceiling) {
            $nssfTier2 = round((min($grossPay, $tier2Ceiling) - $tier1Ceiling) * $nssfRate, 2);
        }
        $nssfTotal = $nssfTier1 + $nssfTier2;

        $taxableIncome = max(0, $grossPay - $nssfTotal);
        $payeGross = $taxBands ? self::computePayeFromBands($taxableIncome, $taxBands) : self::computePaye($taxableIncome);

        $insuranceRelief = min($insurancePremiums * $insReliefRate, $insReliefMax);
        $payePayable     = max(0, $payeGross - $personalRelief - $insuranceRelief);

        $shif = round($grossPay * $shifRate, 2);
        if ($shifMin > 0) $shif = max($shif, $shifMin);
        if ($shifMax > 0) $shif = min($shif, $shifMax);

        $housingLevy    = round($grossPay * $housingLevyRate, 2);
        $totalStatutory = $payePayable + $shif + $nssfTotal + $housingLevy + $nitaAmt;
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
            'nita'             => round($nitaAmt, 2),
            'total_statutory'  => round($totalStatutory, 2),
            'net_pay'          => round($netPay, 2),
        ];
    }

    private static function computePaye(float $taxableIncome): float
    {
        $tax = 0.0; $remaining = $taxableIncome; $prevLimit = 0;
        foreach (self::PAYE_BANDS as $band) {
            if ($remaining <= 0) break;
            $w = ($band['limit'] === PHP_INT_MAX) ? $remaining : min($remaining, $band['limit'] - $prevLimit);
            $tax += $w * $band['rate'];
            $remaining -= $w;
            $prevLimit  = $band['limit'];
        }
        return round($tax, 2);
    }

    private static function computePayeFromBands(float $taxableIncome, array $bands): float
    {
        $tax = 0.0;
        usort($bands, fn($a, $b) => $a['lower_limit'] <=> $b['lower_limit']);
        foreach ($bands as $band) {
            $from = (float)$band['lower_limit'];
            $to   = (isset($band['upper_limit']) && $band['upper_limit'] !== null) ? (float)$band['upper_limit'] : PHP_INT_MAX;
            $rate = (float)$band['rate'] / 100;
            if ($taxableIncome <= $from) break;
            $inBand = min($taxableIncome, $to) - $from;
            if ($inBand > 0) $tax += $inBand * $rate;
        }
        return round($tax, 2);
    }
}
