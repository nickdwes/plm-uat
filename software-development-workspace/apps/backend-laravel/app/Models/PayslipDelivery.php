<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to get one payroll document to one person by one channel.
 *
 * Payslips and P9s both live here. They are the same act — a locked PDF to a
 * personal address — and the question asked afterwards is the same one, so
 * answering it from two tables would eventually answer it two ways.
 */
class PayslipDelivery extends Model
{
    protected $table = 'payroll_payslip_deliveries';

    public const TYPE_PAYSLIP = 'payslip';
    public const TYPE_P9      = 'p9';

    public const CHANNEL_EMAIL  = 'email';
    public const CHANNEL_PORTAL = 'portal';

    public const STATUS_SENT   = 'sent';
    public const STATUS_FAILED = 'failed';

    /*
     * Not an error, and not to be retried: there is nothing to build a password
     * from, so a person has to fix a record before this can ever succeed.
     * Retrying it changes nothing and buries it among real failures.
     */
    public const STATUS_HELD = 'held';

    protected $fillable = [
        'document_type', 'payroll_period_id', 'tax_year', 'employee_id', 'channel',
        'status', 'recipient', 'detail', 'sent_at', 'sent_by',
    ];

    protected $casts = ['sent_at' => 'datetime', 'tax_year' => 'integer'];

    protected $attributes = ['document_type' => self::TYPE_PAYSLIP];

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
