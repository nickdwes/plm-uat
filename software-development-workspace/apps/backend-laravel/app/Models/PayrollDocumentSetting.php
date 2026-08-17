<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How this organisation locks its payslips.
 *
 * There is no "protected" flag. A payslip carries a salary, a tax number and
 * bank details, is emailed to a personal address and forwarded from there — so
 * the only question is what the password is, never whether there is one.
 */
class PayrollDocumentSetting extends Model
{
    /** What the password may be derived from, and where each is read. */
    public const SOURCES = [
        'national_id'     => 'National ID number',
        'employee_number' => 'Employee number',
        'kra_pin'         => 'KRA PIN',
        'date_of_birth'   => 'Date of birth (DDMMYYYY)',
    ];

    public const CASES = ['upper', 'lower', 'as_recorded'];

    protected $fillable = [
        'organization_id',
        'password_source', 'password_fallback_source',
        'password_prefix', 'password_suffix', 'password_case',
        'owner_password', 'allow_print', 'allow_copy',
    ];

    protected $casts = [
        // Governs editing rather than opening, and is never shown to anybody.
        'owner_password' => 'encrypted',
        'allow_print'    => 'boolean',
        'allow_copy'     => 'boolean',
    ];

    protected $attributes = [
        'password_source'          => 'national_id',
        'password_fallback_source' => 'employee_number',
        'password_case'            => 'upper',
        'allow_print'              => true,
        'allow_copy'               => false,
    ];

    /** Never serialised: it is the one value here that is a secret. */
    protected $hidden = ['owner_password'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Settings for an organisation, creating the safe default if absent.
     *
     * An organisation added after this table existed must not be the one that
     * emits an unlocked payslip because nobody visited a settings page.
     */
    public static function forOrganization(int $organizationId): self
    {
        return static::firstOrCreate(
            ['organization_id' => $organizationId],
            ['owner_password' => bin2hex(random_bytes(16))],
        );
    }

    /** A sentence for the review screen, so somebody can check it before sending. */
    public function describe(): string
    {
        $parts = [self::SOURCES[$this->password_source] ?? $this->password_source];

        if ($this->password_prefix) {
            $parts[] = "prefixed \"{$this->password_prefix}\"";
        }

        if ($this->password_suffix) {
            $parts[] = "suffixed \"{$this->password_suffix}\"";
        }

        if ($this->password_case !== 'as_recorded') {
            $parts[] = $this->password_case === 'upper' ? 'in capitals' : 'in lower case';
        }

        return implode(', ', $parts);
    }
}
