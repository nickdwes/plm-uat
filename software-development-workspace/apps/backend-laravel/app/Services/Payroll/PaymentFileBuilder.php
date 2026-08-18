<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;

/**
 * The payroll as a file a bank will accept.
 *
 * Until this existed, paying people meant reading net pay off a screen and
 * typing it into a bank portal a hundred and fifty times. That is where a
 * transposed digit sends somebody's month to a stranger, and where the
 * hundred-and-first person is quietly missed.
 *
 * ── Nobody is dropped silently ──
 *
 * An employee without an account number is *excluded and named*, never omitted.
 * The whole risk of a payment file is that it looks complete: a bank pays what
 * it is given and reports success, and the person who was left out finds out on
 * payday. Same rule the payslip delivery follows, for the same reason.
 *
 * ── On bank-specific layouts ──
 *
 * The formats here are documented CSV shapes, not vendor templates. Every
 * Kenyan bank publishes its own bulk-upload layout with its own column order
 * and header text, and a file that claims to be one and is not gets rejected at
 * best and mispaid at worst. Adding one is a small job *given that bank's spec
 * sheet*; guessing at it is not something worth doing with a payroll.
 */
class PaymentFileBuilder
{
    public const FORMATS = [
        'standard' => 'Standard CSV — full detail, one row per employee',
        'simple'   => 'Simple CSV — account, name and amount only',
    ];

    /**
     * Who would be paid, who would not, and what the totals come to.
     *
     * Answerable before generating anything, because the point of showing it is
     * to find the missing account numbers while there is still time to ask.
     *
     * @return array{rows: array, excluded: array, total: float, count: int}
     */
    public function preview(PayrollPeriod $period): array
    {
        $runs = $period->runs()->with('employee')->get();

        $rows = [];
        $excluded = [];

        foreach ($runs as $run) {
            $employee = $run->employee;

            if (! $employee) {
                continue;
            }

            $net = round((float) $run->net_pay, 2);

            if ($net <= 0) {
                /*
                 * Not an error. A month where deductions swallowed the pay is
                 * a real outcome, and a zero-value instruction is something
                 * banks reject the whole file over.
                 */
                $excluded[] = $this->exclusion($employee, $net, 'Nothing to pay this month');
                continue;
            }

            $reason = $this->whyNotPayable($employee);

            if ($reason !== null) {
                $excluded[] = $this->exclusion($employee, $net, $reason);
                continue;
            }

            $rows[] = [
                'employee_id'    => $employee->id,
                'employee_number'=> $employee->employee_number,
                'name'           => $employee->full_name,
                // The name the bank holds, which is not always the name payroll
                // holds — a mismatch is what a bulk file bounces on.
                'account_name'   => $employee->bank_account_name ?: $employee->full_name,
                'account_number' => $employee->bank_account_number,
                'bank_name'      => $employee->bank_name,
                'bank_code'      => $employee->bank_code,
                'branch_code'    => $employee->branch_code,
                'amount'         => $net,
            ];
        }

        usort($rows, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return [
            'rows'     => $rows,
            'excluded' => $excluded,
            'count'    => count($rows),
            'total'    => round(array_sum(array_column($rows, 'amount')), 2),
        ];
    }

    /**
     * The file itself.
     *
     * A control row closes it — the count and the sum the bank should see. That
     * pair is what catches a file truncated in transit; without it a short file
     * looks like a complete one and pays whoever happened to survive.
     */
    public function build(array $preview, string $format, string $reference, ?string $valueDate = null): string
    {
        $rows = $preview['rows'];

        $lines = [];

        if ($format === 'simple') {
            $lines[] = $this->csv(['Account Number', 'Account Name', 'Amount', 'Reference']);

            foreach ($rows as $r) {
                $lines[] = $this->csv([
                    $r['account_number'],
                    $r['account_name'],
                    number_format($r['amount'], 2, '.', ''),
                    $reference,
                ]);
            }
        } else {
            $lines[] = $this->csv([
                'Employee Number', 'Account Name', 'Account Number', 'Bank', 'Bank Code',
                'Branch Code', 'Amount', 'Currency', 'Value Date', 'Reference', 'Narration',
            ]);

            foreach ($rows as $r) {
                $lines[] = $this->csv([
                    $r['employee_number'],
                    $r['account_name'],
                    $r['account_number'],
                    $r['bank_name'],
                    $r['bank_code'],
                    $r['branch_code'],
                    number_format($r['amount'], 2, '.', ''),
                    'KES',
                    $valueDate,
                    $reference,
                    // What shows on the employee's statement. Kept short and
                    // free of the amount: bank narrations are truncated, and a
                    // truncated figure is worse than none.
                    'Salary ' . $reference,
                ]);
            }
        }

        $lines[] = $this->csv([
            'TOTAL',
            (string) count($rows),
            number_format(round(array_sum(array_column($rows, 'amount')), 2), 2, '.', ''),
        ]);

        return implode("\r\n", $lines) . "\r\n";
    }

    /** Why this person cannot be in the file, in words somebody can act on. */
    private function whyNotPayable(Employee $employee): ?string
    {
        $method = $employee->payment_method ?: 'bank';

        if ($method === 'cash' || $method === 'cheque') {
            return 'Paid by ' . $method . ', not by transfer';
        }

        if ($method === 'mobile_money') {
            return filled($employee->mobile_money_number)
                ? null
                : 'No mobile money number on file';
        }

        if (blank($employee->bank_account_number)) {
            return 'No bank account number on file';
        }

        if (blank($employee->bank_name) && blank($employee->bank_code)) {
            return 'No bank on file — the account number alone will not route';
        }

        return null;
    }

    private function exclusion(Employee $employee, float $net, string $reason): array
    {
        return [
            'employee_id'     => $employee->id,
            'employee_number' => $employee->employee_number,
            'name'            => $employee->full_name,
            'amount'          => $net,
            'reason'          => $reason,
        ];
    }

    /** Quoted throughout: a name with a comma in it must not shift the columns. */
    private function csv(array $fields): string
    {
        return implode(',', array_map(
            fn ($f) => '"' . str_replace('"', '""', (string) $f) . '"',
            $fields,
        ));
    }
}
