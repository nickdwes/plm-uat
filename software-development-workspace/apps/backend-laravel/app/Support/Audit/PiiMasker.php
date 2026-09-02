<?php

namespace App\Support\Audit;

/**
 * What of a changed value may be written to the audit trail, and in what form.
 *
 * An audit log must answer "who changed what, when" without becoming a second,
 * unencrypted copy of the very data protection rules restrict. So every value
 * is classified before it is stored:
 *
 *   REDACT — the value never enters the log. Government IDs, financial figures,
 *            health data, bank details, secrets: the field is recorded as
 *            having changed, the value is withheld. (Data minimisation.)
 *   MASK   — a partial value is stored, enough to recognise but not to reuse:
 *            an email as j***@example.com, a phone as ****4567. (Pseudonymisation.)
 *   CLEAR  — operational, non-personal fields (status, department id, dates
 *            that are not a birth date): stored as-is.
 *
 * Classification is by field name, with an explicit high-risk list and pattern
 * fallbacks, so a new sensitive column is caught by its name rather than
 * needing every model annotated. A model may still override via
 * $auditRedact / $auditMask.
 */
class PiiMasker
{
    /** Exact field names whose value must never be stored. */
    private const REDACT_FIELDS = [
        'national_id', 'passport_number', 'tax_id', 'kra_pin', 'nssf_number', 'nhif_number', 'shif_number',
        'date_of_birth', 'blood_group', 'allergies', 'known_conditions', 'disabilities', 'medical_notes',
        'basic_salary', 'house_allowance', 'transport_allowance', 'medical_allowance', 'other_allowances',
        'insurance_relief', 'gross_salary', 'net_salary', 'bank_account_number', 'bank_account_name',
        'mobile_money_number', 'work_permit_number', 'address', 'residential_address', 'home_address',
        'client_secret', 'client_id', 'tenant_id', 'password', 'remember_token',
    ];

    /** Substrings that, if present in a field name, force redaction. */
    private const REDACT_PATTERNS = [
        'password', 'secret', 'token', 'salary', 'allowance', 'account_number', 'card', 'cvv',
        'ssn', 'national_id', 'passport', 'tax_id', 'kra', 'medical', 'health', 'diagnos',
    ];

    /** Fields stored as a partial mask rather than withheld entirely. */
    private const MASK_EMAIL = ['email', 'work_email', 'personal_email'];
    private const MASK_TAIL  = ['phone', 'mobile', 'msisdn', 'telephone', 'contact_number'];

    /**
     * The value to store for a changed field — or a sentinel that the whole
     * pair should be withheld.
     *
     * @return array{store: bool, value: mixed} store=false means redact the pair.
     */
    public static function forField(string $field, mixed $value, array $redactExtra = [], array $maskExtra = []): array
    {
        $name = strtolower($field);

        if (in_array($name, array_merge(self::REDACT_FIELDS, $redactExtra), true) || self::matchesRedactPattern($name)) {
            return ['store' => false, 'value' => null];
        }

        if ($value === null || $value === '') {
            return ['store' => true, 'value' => $value];
        }

        if (in_array($name, array_merge(self::MASK_EMAIL, $maskExtra), true) || str_contains($name, 'email')) {
            return ['store' => true, 'value' => self::maskEmail((string) $value)];
        }

        foreach (self::MASK_TAIL as $needle) {
            if (str_contains($name, $needle)) {
                return ['store' => true, 'value' => self::maskTail((string) $value)];
            }
        }

        return ['store' => true, 'value' => $value];
    }

    private static function matchesRedactPattern(string $name): bool
    {
        foreach (self::REDACT_PATTERNS as $needle) {
            if (str_contains($name, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Public because the contact-classification resources reuse the exact
     * same mask — a caller without `accounts.view_contacts` sees the same
     * `j***@acme.com` an auditor sees, one format everywhere.
     */
    public static function maskEmail(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false || $at === 0) {
            return '***';
        }
        return $email[0] . str_repeat('*', max(1, $at - 1)) . substr($email, $at);
    }

    public static function maskTail(string $value): string
    {
        $digits = preg_replace('/\D/', '', $value);
        $tail = substr((string) $digits, -4);
        return $tail === '' ? '***' : str_repeat('*', 4) . $tail;
    }
}
