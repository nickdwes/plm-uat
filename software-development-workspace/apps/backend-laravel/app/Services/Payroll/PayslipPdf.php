<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\PayrollDocumentSetting;
use App\Models\PayrollRun;

/**
 * A payslip as a PDF.
 *
 * The layout lives here; the locking lives in ProtectedPdf, which is the single
 * door every payroll document goes through. That separation is the point: a
 * second document type growing its own encryption call is how "nothing leaves
 * unlocked" stops being true.
 */
class PayslipPdf
{
    public function __construct(
        private readonly ProtectedPdf $pdf = new ProtectedPdf(),
        private readonly PayslipTemplate $template = new PayslipTemplate(),
    ) {}

    /**
     * @return array{content: string, filename: string, password_hint: string}
     */
    public function render(PayrollRun $run): array
    {
        $employee = $run->employee
            ?? Employee::withoutGlobalScope('organization')->findOrFail($run->employee_id);
        $period   = $run->period;

        $settings = PayrollDocumentSetting::forOrganization($period->organization_id);

        return $this->pdf->render(
            html:     $this->template->html($run, $employee, $period),
            employee: $employee,
            settings: $settings,
            title:    "Payslip — {$employee->full_name} — {$period->name}",
            filename: $this->pdf->safeName('Payslip', $employee->full_name, $period->name),
            author:   $period->organization->name ?? 'Payroll',
        );
    }

    /** Kept so callers need not know where the derivation lives. */
    public function passwordFor(Employee $employee, PayrollDocumentSetting $settings): string
    {
        return $this->pdf->passwordFor($employee, $settings);
    }

    public function canDeriveFor(Employee $employee, PayrollDocumentSetting $settings): bool
    {
        return $this->pdf->canDeriveFor($employee, $settings);
    }
}
