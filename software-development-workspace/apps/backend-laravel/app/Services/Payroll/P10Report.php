<?php

namespace App\Services\Payroll;

use App\Models\PayrollPeriod;
use App\Models\PayrollRun;

/**
 * The employer's monthly PAYE return, as data.
 *
 * Filed on iTax by pasting into KRA's own template, which they revise and which
 * validates on upload — so this produces the figures and deliberately not the
 * spreadsheet. A hand-built .xlsx claiming to be KRA's fails validation at
 * best, and at worst is accepted with its columns one place out.
 *
 * ── Every figure is the stored one ──
 *
 * Read from the run, never recomputed. The P9 once derived its own taxable
 * income and disagreed with the PAYE printed beside it; the P10 is the same
 * numbers going to the same authority a month at a time, and two documents
 * disagreeing about one person's tax ends in an audit rather than a correction.
 *
 * ── Nobody without a PIN is included quietly ──
 *
 * iTax rejects a sheet with a blank PIN, and rejects the whole sheet rather
 * than the row. So they are separated out and named here, while there is still
 * time to go and ask somebody for their PIN.
 */
class P10Report
{
    /**
     * @return array{rows: array, missing: array, totals: array, excluded_paye: float}
     */
    public function forPeriod(PayrollPeriod $period): array
    {
        $runs = $period->runs()->with('employee')->get();

        $rows    = [];
        $missing = [];

        foreach ($runs as $run) {
            $employee = $run->employee;

            if (! $employee) {
                continue;
            }

            $row = $this->row($run, $employee);

            if (blank($employee->tax_id)) {
                /*
                 * Kept with its figures rather than reduced to a name: whoever
                 * is chasing the PIN is the person who needs to know how much
                 * tax is sitting outside the return.
                 */
                $missing[] = $row + ['reason' => 'No KRA PIN on file'];
                continue;
            }

            $rows[] = $row;
        }

        usort($rows, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return [
            'rows'    => $rows,
            'missing' => $missing,
            'totals'  => $this->totals($rows),
            /*
             * What the return will say against what payroll actually deducted.
             * Producing this from stored figures is what makes them agree; if
             * they ever differ, the difference is the missing PINs and nothing
             * else.
             */
            'excluded_paye' => round(array_sum(array_column($missing, 'paye_payable')), 2),
        ];
    }

    /** The columns KRA's sheet asks for, in the order it asks for them. */
    public function csv(array $report): string
    {
        $header = [
            'PIN of Employee', 'Name of Employee', 'Residential Status', 'Type of Employee',
            'Basic Salary', 'House Allowance', 'Transport Allowance', 'Other Allowances',
            'Value of Quarters', 'Total Gross Pay',
            'Defined Contribution Retirement Scheme', 'Affordable Housing Levy',
            'Post Retirement Medical Fund (SHIF)', 'Owner Occupied Interest',
            'Total Allowable Deductions', 'Chargeable Pay',
            'Tax Payable on Chargeable Pay', 'Monthly Personal Relief', 'Amount of Insurance Relief',
            'PAYE Tax (J)',
        ];

        $lines = [$this->line($header)];

        foreach ($report['rows'] as $r) {
            $lines[] = $this->line([
                $r['kra_pin'], $r['name'], 'Resident', 'Primary Employee',
                $this->n($r['basic_salary']), $this->n($r['house_allowance']),
                $this->n($r['transport_allowance']), $this->n($r['other_earnings']),
                $this->n($r['value_of_quarters']), $this->n($r['gross_pay']),
                $this->n($r['pension']), $this->n($r['housing_levy']),
                $this->n($r['shif']), $this->n($r['owner_occupied_interest']),
                $this->n($r['allowable_deductions']), $this->n($r['chargeable_pay']),
                $this->n($r['tax_on_chargeable']), $this->n($r['personal_relief']),
                $this->n($r['insurance_relief']), $this->n($r['paye_payable']),
            ]);
        }

        /*
         * What the employer remits. The return and the payment have to agree,
         * and this is the figure somebody checks one against the other — so it
         * is on the file rather than left to a spreadsheet formula that may or
         * may not survive the paste.
         */
        $t = $report['totals'];
        $lines[] = $this->line([
            'TOTAL', (string) $t['employees'], '', '',
            '', '', '', '', '', $this->n($t['gross_pay']),
            '', '', '', '', $this->n($t['allowable_deductions']), $this->n($t['chargeable_pay']),
            '', '', '', $this->n($t['paye_payable']),
        ]);

        return implode("\r\n", $lines) . "\r\n";
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function row(PayrollRun $run, $employee): array
    {
        /*
         * NSSF is the defined-contribution figure on the return. Tiers I and II
         * are a domestic distinction; KRA asks for the contribution.
         */
        $pension = round((float) $run->nssf_tier1 + (float) $run->nssf_tier2, 2);

        return [
            'employee_id'     => $employee->id,
            'employee_number' => $employee->employee_number,
            'name'            => $employee->full_name,
            'kra_pin'         => $employee->tax_id,

            'basic_salary'        => round((float) $run->basic_salary, 2),
            'house_allowance'     => round((float) $run->house_allowance, 2),
            'transport_allowance' => round((float) $run->transport_allowance, 2),
            'other_earnings'      => round((float) $run->other_earnings, 2),

            // Employer-provided housing is not modelled, and is reported as
            // nought rather than omitted: the column exists on the return, and
            // a missing column reads as an error rather than an absence.
            'value_of_quarters'   => 0.0,

            'gross_pay'    => round((float) $run->gross_pay, 2),
            'pension'      => $pension,
            'shif'         => round((float) $run->shif, 2),
            'housing_levy' => round((float) $run->housing_levy, 2),

            // Not modelled yet; nought for the same reason.
            'owner_occupied_interest' => 0.0,

            /*
             * The stored figures, not a re-derivation. Older runs predate these
             * columns and fall back to the arithmetic their own PAYE was
             * computed from, which is the only answer consistent with the
             * payslip already issued.
             */
            'allowable_deductions' => round(
                (float) ($run->allowable_deductions ?? ($pension + (float) $run->shif + (float) $run->housing_levy)),
                2,
            ),
            'chargeable_pay' => round(
                (float) ($run->taxable_income ?? ((float) $run->gross_pay - $pension)),
                2,
            ),

            'tax_on_chargeable' => round((float) $run->paye, 2),
            'personal_relief'   => round((float) $run->personal_relief, 2),
            'insurance_relief'  => round((float) $run->insurance_relief, 2),
            'paye_payable'      => round((float) $run->paye_payable, 2),
        ];
    }

    private function totals(array $rows): array
    {
        $sum = fn (string $key) => round(array_sum(array_column($rows, $key)), 2);

        return [
            'employees'            => count($rows),
            'gross_pay'            => $sum('gross_pay'),
            'allowable_deductions' => $sum('allowable_deductions'),
            'chargeable_pay'       => $sum('chargeable_pay'),
            'personal_relief'      => $sum('personal_relief'),
            'insurance_relief'     => $sum('insurance_relief'),
            // What is remitted to KRA for the month.
            'paye_payable'         => $sum('paye_payable'),
        ];
    }

    private function n(float $v): string
    {
        return number_format($v, 2, '.', '');
    }

    /** Quoted throughout: a name with a comma must not shift the columns. */
    private function line(array $fields): string
    {
        return implode(',', array_map(
            fn ($f) => '"' . str_replace('"', '""', (string) $f) . '"',
            $fields,
        ));
    }
}
