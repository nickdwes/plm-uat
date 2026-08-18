<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollRun extends Model
{
    public const STATUS_DRAFT    = 'draft';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PAID     = 'paid';

    protected $fillable = [
        'payroll_period_id',
        'employee_id',
        'basic_salary',
        'house_allowance',
        'transport_allowance',
        'other_earnings',
        'gross_pay',
        // Paid, but outside the statutory base — kept apart so gross_pay stays
        // the figure the engine taxed and the P9 reports.
        'non_taxable_earnings',
        // What standing orders took this month. Stored beside total_deductions
        // so a payslip can say which part of the total was a loan repayment.
        'recurring_deductions',
        // Written once by the engine and read everywhere after — the P9 used
        // to derive its own and drifted from the PAYE beside it.
        'taxable_income',
        'allowable_deductions',
        'paye',
        'shif',
        'nssf_tier1',
        'nssf_tier2',
        'housing_levy',
        'nita',
        'personal_relief',
        'insurance_relief',
        'paye_payable',
        'total_deductions',
        'net_pay',
        'earnings',
        'deductions',
        'status',
    ];

    protected $casts = [
        'basic_salary'       => 'decimal:2',
        'house_allowance'    => 'decimal:2',
        'transport_allowance'=> 'decimal:2',
        'other_earnings'     => 'decimal:2',
        'gross_pay'          => 'decimal:2',
        'paye'               => 'decimal:2',
        'shif'               => 'decimal:2',
        'nssf_tier1'         => 'decimal:2',
        'nssf_tier2'         => 'decimal:2',
        'housing_levy'       => 'decimal:2',
        'nita'               => 'decimal:2',
        'personal_relief'    => 'decimal:2',
        'insurance_relief'   => 'decimal:2',
        'paye_payable'       => 'decimal:2',
        'total_deductions'   => 'decimal:2',
        'net_pay'            => 'decimal:2',
        'earnings'           => 'array',
        'deductions'         => 'array',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayrollRunItem::class);
    }
}
