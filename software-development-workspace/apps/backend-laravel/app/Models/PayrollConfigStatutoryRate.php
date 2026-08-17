<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollConfigStatutoryRate extends Model
{
    use BelongsToOrganization;

    protected $table = 'payroll_config_statutory_rates';

    // All supported parameter keys
    public const PARAM_KEYS = [
        'personal_relief',
        'insurance_relief_rate',
        'insurance_relief_max',
        'shif_rate',
        'shif_min',
        'shif_max',
        'nssf_tier1_ceiling',
        'nssf_tier2_ceiling',
        'nssf_employee_rate',
        'nssf_employer_rate',
        'housing_levy_employee_rate',
        'housing_levy_employer_rate',
        'nita_amount',
        /*
         * 1 to take the industrial training levy off the employee, 0 to leave
         * it as the employer's — which is what the law says and the default.
         * A flag rather than a word because `value` is a decimal column, and
         * bending it to hold text would break every other reader.
         */
        'nita_on_employee',
        // Monthly caps on what may be set against income before PAYE.
        'pension_relief_max',
        'post_retirement_medical_max',
        'mortgage_interest_max',
    ];

    // Kenya 2025/2026 defaults (used if no DB config exists)
    public const DEFAULTS = [
        'personal_relief'             => 2400,
        'insurance_relief_rate'       => 15,    // percent
        'insurance_relief_max'        => 5000,
        'shif_rate'                   => 2.75,  // percent
        'shif_min'                    => 0,
        'shif_max'                    => 0,     // 0 = no cap
        // NSSF Act 2013 as in force from February 2025. The old 7,000 and
        // 36,000 were the year-two limits and sat here two years out of date.
        'nssf_tier1_ceiling'          => 8000,
        'nssf_tier2_ceiling'          => 72000,
        'nssf_employee_rate'          => 6,     // percent
        'nssf_employer_rate'          => 6,     // percent
        'housing_levy_employee_rate'  => 1.5,   // percent
        'housing_levy_employer_rate'  => 1.5,   // percent
        'nita_amount'                 => 50,
        /*
         * The training levy is the employer's, so nought. Every key in
         * PARAM_KEYS needs an entry here — the configuration screen reads both
         * lists together, and adding to one without the other is what made it
         * 500 on "Undefined array key".
         */
        'nita_on_employee'            => 0,
        'pension_relief_max'          => 30000,
        'post_retirement_medical_max' => 15000,
        'mortgage_interest_max'       => 30000,
    ];

    public const DESCRIPTIONS = [
        'personal_relief'             => 'Monthly personal relief (KES)',
        'insurance_relief_rate'       => 'Insurance relief rate (%)',
        'insurance_relief_max'        => 'Insurance relief maximum per month (KES)',
        'shif_rate'                   => 'SHIF rate (% of gross)',
        'shif_min'                    => 'SHIF minimum contribution (KES, 0 = no minimum)',
        'shif_max'                    => 'SHIF maximum contribution (KES, 0 = no cap)',
        'nssf_tier1_ceiling'          => 'NSSF Tier I upper ceiling (KES)',
        'nssf_tier2_ceiling'          => 'NSSF Tier II upper ceiling (KES)',
        'nssf_employee_rate'          => 'NSSF employee contribution rate (%)',
        'nssf_employer_rate'          => 'NSSF employer contribution rate (%)',
        'housing_levy_employee_rate'  => 'Housing Levy employee rate (%)',
        'housing_levy_employer_rate'  => 'Housing Levy employer rate (%)',
        'nita_amount'                 => 'NITA flat levy per employee per month (KES)',
            'nita_on_employee'            => 'Pass the NITA levy on to the employee (1 = yes, 0 = employer pays)',
        'pension_relief_max'          => 'Pension contribution allowed before PAYE, monthly cap (KES)',
        'post_retirement_medical_max' => 'Post-retirement medical fund allowed before PAYE, monthly cap (KES)',
        'mortgage_interest_max'       => 'Mortgage interest allowed before PAYE, monthly cap (KES)',
];

    protected $fillable = [
        'organization_id',
        'param_key',
        'value',
        'description',
        'effective_date',
        'expires_at',
    ];

    protected $casts = [
        'value'          => 'float',
        'effective_date' => 'date',
        'expires_at'     => 'date',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
