<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\PayrollDocumentSetting;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use RuntimeException;

/**
 * The one place a payroll document becomes bytes.
 *
 * Extracted from PayslipPdf when the P9 needed the same treatment. The rule it
 * enforces — a document carrying somebody's pay cannot be produced unlocked —
 * only holds if there is a single door, and a second class quietly growing its
 * own `SetProtection` call is exactly how that stops being true.
 *
 * The password is derived from something the employee already knows and is
 * never stored. Where it cannot be derived this throws and names the person,
 * because a document that does not arrive is fixed in a minute and one that
 * arrives unlocked cannot be taken back.
 *
 * ── What the protection is ──
 *
 * mPDF encrypts with RC4 at both 40 and 128 bits; it has no AES mode, so this
 * is RC4-128. Every reader demands the password, which is what stops a payslip
 * being read out of a forwarded thread. It is not protection against a
 * determined attacker, and the cipher is not the reason: a password taken from
 * an eight-digit national ID is a hundred million offline guesses whatever
 * encrypts it.
 */
class ProtectedPdf
{
    /**
     * @return array{content: string, filename: string, password_hint: string}
     */
    public function render(
        string $html,
        Employee $employee,
        PayrollDocumentSetting $settings,
        string $title,
        string $filename,
        string $author = '',
    ): array {
        $password = $this->passwordFor($employee, $settings);

        $mpdf = new Mpdf([
            'tempDir'       => storage_path('app/mpdf'),
            'format'        => 'A4',
            'margin_top'    => 12,
            'margin_bottom' => 12,
        ]);

        $permissions = array_values(array_filter([
            $settings->allow_print ? 'print' : null,
            $settings->allow_copy ? 'copy' : null,
        ]));

        // The owner password governs editing and is never disclosed, so a
        // document cannot be quietly altered and passed on as though it came
        // from here.
        $mpdf->SetProtection(
            $permissions,
            $password,
            $settings->owner_password ?: bin2hex(random_bytes(16)),
            128,
        );

        $mpdf->SetTitle($title);
        $mpdf->SetAuthor($author);
        $mpdf->WriteHTML($html);

        return [
            'content'  => $mpdf->Output('', Destination::STRING_RETURN),
            'filename' => $filename,
            // What to tell the recipient, never the value.
            'password_hint' => $settings->describe(),
        ];
    }

    /** Derived from something the employee already knows, never stored. */
    public function passwordFor(Employee $employee, PayrollDocumentSetting $settings): string
    {
        $value = $this->read($employee, $settings->password_source);

        if ($value === null && $settings->password_fallback_source) {
            $value = $this->read($employee, $settings->password_fallback_source);
        }

        if ($value === null) {
            $label = PayrollDocumentSetting::SOURCES[$settings->password_source] ?? $settings->password_source;

            throw new RuntimeException(
                "No password can be built for {$employee->full_name} "
                . "({$employee->employee_number}): {$label} is not on file, and neither is the fallback. "
                . 'Add it before sending — an unprotected payroll document is not an option.',
            );
        }

        $value = $settings->password_prefix . $value . $settings->password_suffix;

        return match ($settings->password_case) {
            'lower'       => mb_strtolower($value),
            'as_recorded' => $value,
            default       => mb_strtoupper($value),
        };
    }

    /** Whether this person can be sent one, without building the document. */
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

        // Spaces and dashes are typed inconsistently, so they are stripped
        // rather than left to chance.
        $clean = preg_replace('/[\s-]+/', '', (string) $raw);

        return $clean === '' ? null : $clean;
    }

    /** A filename that survives a file manager. */
    public function safeName(string ...$parts): string
    {
        $slug = collect($parts)
            ->map(fn ($p) => preg_replace('/[^A-Za-z0-9]+/', '-', trim((string) $p)))
            ->filter()
            ->implode('-');

        return trim($slug, '-') . '.pdf';
    }
}
