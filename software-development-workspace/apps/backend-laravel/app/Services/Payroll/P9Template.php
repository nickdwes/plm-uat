<?php

namespace App\Services\Payroll;

/**
 * The P9A, laid out as KRA prints it.
 *
 * The column letters are on the page — A, B, C, E1, F, J — because this is the
 * one payroll document an employee retypes into somebody else's form. iTax asks
 * for "column F"; a prettier layout that renames the columns turns a two-minute
 * job into a phone call.
 *
 * Landscape, because eleven columns and twelve months do not fit upright.
 */
class P9Template
{
    public function html(array $months, $employee, int $year, string $org): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $m = fn ($v) => number_format((float) $v, 2);

        $rows   = '';
        $totals = array_fill_keys(
            ['basic_pay', 'benefits', 'value_of_quarters', 'gross_pay', 'defined_contribution',
             'taxable_income', 'paye', 'personal_relief', 'paye_payable', 'net_pay'],
            0.0,
        );

        foreach ($months as $row) {
            foreach ($totals as $key => $_) {
                $totals[$key] += (float) ($row[$key] ?? 0);
            }

            $rows .= '<tr>'
                . '<td class="mth">' . $e($row['month'] ?? '') . '</td>'
                . '<td>' . $m($row['basic_pay'] ?? 0) . '</td>'
                . '<td>' . $m($row['benefits'] ?? 0) . '</td>'
                . '<td>' . $m($row['value_of_quarters'] ?? 0) . '</td>'
                . '<td>' . $m($row['gross_pay'] ?? 0) . '</td>'
                . '<td>' . $m($row['defined_contribution'] ?? 0) . '</td>'
                . '<td>' . $m($row['taxable_income'] ?? 0) . '</td>'
                . '<td>' . $m($row['paye'] ?? 0) . '</td>'
                . '<td>' . $m($row['personal_relief'] ?? 0) . '</td>'
                . '<td>' . $m($row['paye_payable'] ?? 0) . '</td>'
                . '<td>' . $m($row['net_pay'] ?? 0) . '</td>'
                . '</tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="11" class="none">No payroll was approved for this employee in ' . $year . '.</td></tr>';
        }

        $total = '<tr class="tot">'
            . '<td class="mth">TOTAL</td>'
            . implode('', array_map(fn ($v) => '<td>' . $m($v) . '</td>', $totals))
            . '</tr>';

        return <<<HTML
<!DOCTYPE html>
<html><head><meta charset="utf-8"><style>
  @page { size: A4 landscape; }
  body { font-family: sans-serif; font-size: 8.5pt; color: #111827; }
  .head { border-bottom: 2px solid #0c8ef4; padding-bottom: 8px; margin-bottom: 12px; }
  .org { font-size: 13pt; font-weight: bold; color: #0c8ef4; }
  .doc { font-size: 9pt; color: #6b7280; }
  .who td { padding: 1px 0; font-size: 9pt; }
  .who .label { color: #6b7280; width: 120px; }
  table.p9 { width: 100%; border-collapse: collapse; margin-top: 12px; }
  table.p9 th, table.p9 td { border: 1px solid #9ca3af; padding: 4px 5px; text-align: right; }
  table.p9 th { background: #f3f4f6; font-size: 7.5pt; text-align: center; }
  table.p9 th .col { display: block; color: #6b7280; font-weight: normal; }
  td.mth, th.mth { text-align: left; }
  tr.tot td { font-weight: bold; background: #eff6ff; }
  td.none { text-align: center; color: #6b7280; }
  .foot { margin-top: 18px; font-size: 7.5pt; color: #6b7280; border-top: 1px solid #e5e7eb; padding-top: 6px; }
</style></head><body>

  <div class="head">
    <div class="org">{$e($org)}</div>
    <div class="doc">Tax Deduction Card (P9A) &middot; Year {$year}</div>
  </div>

  <table class="who">
    <tr><td class="label">Employee</td><td>{$e($employee->full_name)}</td>
        <td class="label">KRA PIN</td><td>{$e($employee->tax_id ?: '—')}</td></tr>
    <tr><td class="label">Employee number</td><td>{$e($employee->employee_number)}</td>
        <td class="label">NSSF number</td><td>{$e($employee->nssf_number ?: '—')}</td></tr>
  </table>

  <table class="p9">
    <thead><tr>
      <th class="mth">MONTH</th>
      <th>BASIC PAY<span class="col">A</span></th>
      <th>BENEFITS<span class="col">B</span></th>
      <th>VALUE OF QUARTERS<span class="col">C</span></th>
      <th>TOTAL GROSS PAY<span class="col">A+B+C</span></th>
      <th>DEFINED CONTRIBUTION<span class="col">E1</span></th>
      <th>CHARGEABLE PAY<span class="col">F</span></th>
      <th>TAX CHARGED<span class="col">G</span></th>
      <th>PERSONAL RELIEF<span class="col">H</span></th>
      <th>PAYE TAX<span class="col">J</span></th>
      <th>NET PAY</th>
    </tr></thead>
    <tbody>{$rows}{$total}</tbody>
  </table>

  <div class="foot">
    This certificate is confidential and is password protected. Computer
    generated; no signature is required. Figures are as recorded on approved
    payroll for the year shown.
  </div>

</body></html>
HTML;
    }
}
