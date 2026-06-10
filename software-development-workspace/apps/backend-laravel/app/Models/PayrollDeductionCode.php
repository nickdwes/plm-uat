<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollDeductionCode extends Model
{
    use BelongsToOrganization;

    protected $table = 'payroll_deduction_codes';

    public const TYPE_STATUTORY = 'statutory';
    public const TYPE_CHECKOFF  = 'checkoff';
    public const TYPE_LOAN      = 'loan';

    public const DEFAULTS = [
        ['code' => 'HELB',       'name' => 'HELB Loan',              'deduction_type' => 'loan',     'priority' => 9,  'calculation_type' => 'fixed',      'max_amount' => null, 'recover_balance' => true],
        ['code' => 'SALARY_ADV', 'name' => 'Salary Advance',         'deduction_type' => 'loan',     'priority' => 10, 'calculation_type' => 'fixed',      'max_amount' => null, 'recover_balance' => true],
        ['code' => 'SACCO',      'name' => 'Sacco Loan',             'deduction_type' => 'checkoff', 'priority' => 11, 'calculation_type' => 'fixed',      'max_amount' => null, 'recover_balance' => false],
        ['code' => 'UNION',      'name' => 'Union Dues',             'deduction_type' => 'checkoff', 'priority' => 12, 'calculation_type' => 'fixed',      'max_amount' => null, 'recover_balance' => false],
        ['code' => 'WELFARE',    'name' => 'Staff Welfare',          'deduction_type' => 'checkoff', 'priority' => 13, 'calculation_type' => 'fixed',      'max_amount' => null, 'recover_balance' => false],
        ['code' => 'COOP',       'name' => 'Cooperative Deduction',  'deduction_type' => 'checkoff', 'priority' => 14, 'calculation_type' => 'fixed',      'max_amount' => null, 'recover_balance' => false],
        ['code' => 'COURT',      'name' => 'Court Order',            'deduction_type' => 'statutory','priority' => 8,  'calculation_type' => 'fixed',      'max_amount' => null, 'recover_balance' => false],
        ['code' => 'MORTGAGE',   'name' => 'Mortgage Recovery',      'deduction_type' => 'loan',     'priority' => 15, 'calculation_type' => 'fixed',      'max_amount' => null, 'recover_balance' => true],
    ];

    protected $fillable = [
        'organization_id',
        'code',
        'name',
        'deduction_type',
        'priority',
        'calculation_type',
        'max_amount',
        'recover_balance',
        'is_active',
    ];

    protected $casts = [
        'priority'        => 'integer',
        'max_amount'      => 'float',
        'recover_balance' => 'boolean',
        'is_active'       => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
