<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\PayrollDocumentSetting;

/**
 * A P9A as a PDF.
 *
 * It carries a year of somebody's pay and their KRA PIN, so it goes through
 * ProtectedPdf like everything else here — same door, same rule, same password
 * the employee already uses on their payslips.
 */
class P9Pdf
{
    public function __construct(
        private readonly ProtectedPdf $pdf = new ProtectedPdf(),
        private readonly P9Template $template = new P9Template(),
    ) {}

    /**
     * @param  array $months rows from PayrollService::getP9Months()
     * @return array{content: string, filename: string, password_hint: string}
     */
    public function render(Employee $employee, int $year, array $months, int $organizationId, string $org = ''): array
    {
        $settings = PayrollDocumentSetting::forOrganization($organizationId);

        return $this->pdf->render(
            html:     $this->template->html($months, $employee, $year, $org),
            employee: $employee,
            settings: $settings,
            title:    "P9A — {$employee->full_name} — {$year}",
            filename: $this->pdf->safeName('P9A', $employee->full_name, (string) $year),
            author:   $org,
        );
    }

    public function canDeriveFor(Employee $employee, PayrollDocumentSetting $settings): bool
    {
        return $this->pdf->canDeriveFor($employee, $settings);
    }
}
