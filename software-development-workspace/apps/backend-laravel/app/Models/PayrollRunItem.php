<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ad-hoc line on one person's run: overtime, a bonus, an advance recovery.
 *
 * Everything the engine needs to treat it correctly is copied onto the row when
 * it is written. A code renamed or re-flagged next quarter must not change what
 * a payslip already said — that document has been filed, and possibly used to
 * get a mortgage.
 */
class PayrollRunItem extends Model
{
    public const TYPE_EARNING   = 'earning';
    public const TYPE_DEDUCTION = 'deduction';

    protected $fillable = [
        'payroll_run_id', 'type', 'label', 'amount',
        'payroll_earning_code_id', 'payroll_deduction_code_id',
        // Which standing order wrote this line. Left out of $fillable it is
        // silently dropped, the link is lost, and every derived balance reads
        // nought while the money still comes off.
        'employee_recurring_deduction_id',
        'is_taxable', 'note', 'created_by',
    ];

    protected $casts = [
        'amount'     => 'decimal:2',
        'is_taxable' => 'boolean',
    ];

    protected $attributes = ['is_taxable' => true];

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function earningCode(): BelongsTo
    {
        return $this->belongsTo(PayrollEarningCode::class, 'payroll_earning_code_id');
    }

    public function deductionCode(): BelongsTo
    {
        return $this->belongsTo(PayrollDeductionCode::class, 'payroll_deduction_code_id');
    }

    /** Paid but outside the statutory base — a per diem, a reimbursement. */
    public function isNonTaxableEarning(): bool
    {
        return $this->type === self::TYPE_EARNING && ! $this->is_taxable;
    }
}
