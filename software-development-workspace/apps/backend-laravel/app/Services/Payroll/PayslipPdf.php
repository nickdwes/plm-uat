<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\PayrollDocumentSetting;
use App\Models\PayrollRun;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use RuntimeException;

/**
 * A payslip as a PDF, and there is only one way to get one.
 *
 * ── Why this is the only door ──
 *
 * A payslip carries a salary, a tax number and bank details. It is emailed to a
 * personal address and forwarded from there to landlords, banks and family. So
 * protection cannot be a flag a caller passes, or an option a send screen
 * offers: it has to be impossible to obtain the bytes without it.
 *
 * Every route out — the download button, the email attachment, anything added
 * later — goes through `render()`, and `render()` has no branch that produces
 * an unprotected document. Where the password cannot be derived it throws and
 * names the person, because a payslip that does not arrive is a problem
 * somebody fixes in a minute, and one that arrives unlocked is a problem
 * nobody can take back.
 */
class PayslipPdf
{
    public function __construct(private readonly PayslipTemplate $template = new PayslipTemplate()) {}

    /**
     * The bytes, always encrypted.
     *
     * @return array{content: string, filename: string, password_hint: string}
     */
    public function render(PayrollRun $run): array
    {
        $employee = $run->employee ?? Employee::withoutGlobalScope('organization')->findOrFail($run->employee_id);
        $period   = $run->period;

        $settings = PayrollDocumentSetting::forOrganization($period->organization_id);
        $password = $this->passwordFor($employee, $settings);

        $mpdf = new Mpdf([
            'tempDir' => storage_path('app/mpdf'),
            'format'  => 'A4',
            'margin_top' => 12,
            'margin_bottom' => 12,
        ]);

        /*
         * The user password opens it; the owner password governs editing and is
         * never disclosed, so a payslip cannot be quietly altered and passed on
         * as though it came from here.
         *
         * ── What this protection is, exactly ──
         *
         * mPDF encrypts with RC4 at both 40 and 128 bits; it has no AES mode,
         * so 128 here is RC4-128 and not something stronger. Every standard
         * reader demands the password, which is what stops a payslip being read
         * out of a forwarded email thread — the threat this exists for.
         *
         * It is not protection against a determined attacker, and the cipher is
         * not the reason. A password derived from an eight-digit national ID is
         * a hundred million guesses offline whatever it is encrypted with, so
         * the entropy binds long before RC4 does. Moving to AES would mean
         * changing PDF library; making the password meaningfully hard to guess
         * would mean not deriving it from something printed on an ID card.
         * Both are worth doing and neither is done here.
         */
        $permissions = array_values(array_filter([
            $settings->allow_print ? 'print' : null,
            $settings->allow_copy ? 'copy' : null,
        ]));

        $mpdf->SetProtection($permissions, $password, $settings->owner_password ?: bin2hex(random_bytes(16)), 128);

        $mpdf->SetTitle("Payslip — {$employee->full_name} — {$period->name}");
        $mpdf->SetAuthor($period->organization->name ?? 'Payroll');
        $mpdf->WriteHTML($this->template->html($run, $employee, $period));

        return [
            'content'  => $mpdf->Output('', Destination::STRING_RETURN),
            'filename' => $this->filename($employee, $period),
            /*
             * What to tell the recipient, never the password itself. "Your
             * national ID in capitals" is something they can act on; the value
             * in the covering email would defeat the whole exercise.
             */
            'password_hint' => $settings->describe(),
        ];
    }

    /**
     * The password, derived from something the employee already knows.
     *
     * Never generated and stored: a password this system holds is one it can
     * leak, and one the recipient must be told — which usually means telling
     * them in the same email as the attachment.
     */
    public function passwordFor(Employee $employee, PayrollDocumentSetting $settings): string
    {
        $value = $this->read($employee, $settings->password_source);

        if ($value === null && $settings->password_fallback_source) {
            $value = $this->read($employee, $settings->password_fallback_source);
        }

        if ($value === null) {
            $label = PayrollDocumentSetting::SOURCES[$settings->password_source] ?? $settings->password_source;

            throw new RuntimeException(
                "No payslip password can be built for {$employee->full_name} "
                . "({$employee->employee_number}): {$label} is not on file, and neither is the fallback. "
                . 'Add it before sending — an unprotected payslip is not an option.',
            );
        }

        $value = $settings->password_prefix . $value . $settings->password_suffix;

        return match ($settings->password_case) {
            'lower' => mb_strtolower($value),
            'as_recorded' => $value,
            default => mb_strtoupper($value),
        };
    }

    /** Whether this person can be sent one at all, without building the document. */
    public function canDeriveFor(Employee $employee, PayrollDocumentSetting $settings): bool
    {
        return $this->read($employee, $settings->password_source) !== null
            || ($settings->password_fallback_source !== null
                && $this->read($employee, $settings->password_fallback_source) !== null);
    }

    private function read(Employee $employee, ?string $source): ?string
    {
        $raw = match ($source) {
            'national_id'     => $employee->national_id,
            'employee_number' => $employee->employee_number,
            'kra_pin'         => $employee->tax_id,
            'date_of_birth'   => $employee->date_of_birth?->format('dmY'),
            default           => null,
        };

        // Spaces and dashes are typed inconsistently, so they are stripped from
        // the password rather than left to chance.
        $clean = preg_replace('/[\s-]+/', '', (string) $raw);

        return $clean === '' ? null : $clean;
    }

    private function filename(Employee $employee, $period): string
    {
        $name = preg_replace('/[^A-Za-z0-9]+/', '-', trim($employee->full_name));
        $when = preg_replace('/[^A-Za-z0-9]+/', '-', trim($period->name));

        return trim("Payslip-{$name}-{$when}", '-') . '.pdf';
    }
}
