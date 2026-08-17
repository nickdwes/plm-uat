<?php

namespace App\Services\Payroll;

/**
 * The payslip itself.
 *
 * Laid out as the designs draw it: earnings, then statutory deductions with the
 * rate stated beside each, then net pay. The rates are written on the line —
 * "SHIF (2.75% of gross)", "NSSF Tier I (6% of first 8,000)" — because a payslip
 * is the only document most people ever check their deductions against, and a
 * bare number invites a question that costs somebody an afternoon.
 *
 * Built as a string rather than a Blade view: it is one document with one
 * layout, and a template file would put half of it somewhere else for no gain.
 */
class PayslipTemplate
{
    public function html($run, $employee, $period): string
    {
        $org = $period->organization?->name ?? '';
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $m = fn ($v) => number_format((float) $v, 2);

        $earnings = $this->rows([
            ['Basic salary', $run->basic_salary],
            ['House allowance', $run->house_allowance],
            ['Transport allowance', $run->transport_allowance],
            ['Other earnings', $run->other_earnings],
        ], $m);

        /*
         * Only what actually came off. NITA is the employer's and does not
         * appear here — showing a line the employee did not pay is how a
         * payslip starts an argument it cannot settle.
         */
        $deductions = $this->rows([
            ['PAYE (after personal relief of ' . $m($run->personal_relief) . ')', $run->paye_payable],
            ['SHIF (2.75% of gross)', $run->shif],
            ['NSSF Tier I', $run->nssf_tier1],
            ['NSSF Tier II', $run->nssf_tier2],
            ['Affordable Housing Levy (1.5%)', $run->housing_levy],
        ], $m);

        $gross = $m($run->gross_pay);
        $total = $m($run->total_deductions);
        $net   = $m($run->net_pay);

        return <<<HTML
<!DOCTYPE html>
<html><head><meta charset="utf-8"><style>
  body { font-family: sans-serif; font-size: 11pt; color: #111827; }
  .head { border-bottom: 2px solid #0c8ef4; padding-bottom: 10px; margin-bottom: 18px; }
  .org { font-size: 15pt; font-weight: bold; color: #0c8ef4; }
  .doc { font-size: 10pt; color: #6b7280; }
  .who td { padding: 2px 0; font-size: 10pt; }
  .who .label { color: #6b7280; width: 130px; }
  h2 { font-size: 11pt; margin: 20px 0 6px; padding: 6px 8px; background: #f3f4f6; }
  table.lines { width: 100%; border-collapse: collapse; }
  table.lines td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; }
  td.amt { text-align: right; white-space: nowrap; }
  tr.total td { font-weight: bold; border-top: 1px solid #9ca3af; border-bottom: none; }
  tr.net td { font-weight: bold; font-size: 12pt; background: #eff6ff; color: #0c8ef4; }
  .foot { margin-top: 26px; font-size: 8pt; color: #6b7280; border-top: 1px solid #e5e7eb; padding-top: 8px; }
</style></head><body>

  <div class="head">
    <div class="org">{$e($org)}</div>
    <div class="doc">Payslip &middot; {$e($period->name)}</div>
  </div>

  <table class="who">
    <tr><td class="label">Employee</td><td>{$e($employee->full_name)}</td></tr>
    <tr><td class="label">Employee number</td><td>{$e($employee->employee_number)}</td></tr>
    <tr><td class="label">KRA PIN</td><td>{$e($employee->tax_id ?: '—')}</td></tr>
    <tr><td class="label">NSSF number</td><td>{$e($employee->nssf_number ?: '—')}</td></tr>
    <tr><td class="label">Period</td><td>{$e($period->start_date?->toFormattedDateString())} &ndash; {$e($period->end_date?->toFormattedDateString())}</td></tr>
  </table>

  <h2>Earnings</h2>
  <table class="lines">
    {$earnings}
    <tr class="total"><td>Gross pay</td><td class="amt">{$gross}</td></tr>
  </table>

  <h2>Statutory deductions</h2>
  <table class="lines">
    {$deductions}
    <tr class="total"><td>Total deductions</td><td class="amt">{$total}</td></tr>
  </table>

  <table class="lines" style="margin-top:14px">
    <tr class="net"><td>Net pay</td><td class="amt">{$net}</td></tr>
  </table>

  <div class="foot">
    This payslip is confidential and is password protected. Computer generated;
    no signature is required.
  </div>

</body></html>
HTML;
    }

    /** Nought-value lines are dropped: a column of zeroes hides the real ones. */
    private function rows(array $lines, callable $money): string
    {
        $html = '';

        foreach ($lines as [$label, $amount]) {
            if ((float) $amount <= 0) {
                continue;
            }

            $safe = htmlspecialchars($label, ENT_QUOTES);
            $html .= "<tr><td>{$safe}</td><td class=\"amt\">{$money($amount)}</td></tr>";
        }

        return $html ?: '<tr><td colspan="2" style="color:#6b7280">Nothing recorded.</td></tr>';
    }
}
