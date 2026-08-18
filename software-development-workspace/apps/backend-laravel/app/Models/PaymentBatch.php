<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One payment file that left this system, and what was in it. */
class PaymentBatch extends Model
{
    use BelongsToOrganization;

    protected $table = 'payroll_payment_batches';

    protected $fillable = [
        'organization_id', 'payroll_period_id', 'reference', 'format', 'value_date',
        'record_count', 'total_amount', 'excluded_count', 'excluded', 'generated_by',
    ];

    protected $casts = [
        'record_count'   => 'integer',
        'total_amount'   => 'decimal:2',
        'excluded_count' => 'integer',
        'excluded'       => 'array',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    /**
     * A reference the bank and this system can both quote.
     *
     * Sequential per period rather than random: a second file for the same
     * month is a real thing that happens after a correction, and "-02" says so
     * where a random string would leave somebody guessing which was which.
     */
    public static function nextReference(int $organizationId, PayrollPeriod $period): string
    {
        $count = static::withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->where('payroll_period_id', $period->id)
            ->count();

        return sprintf(
            'PAY-%s-%02d',
            $period->start_date?->format('Ym') ?? 'UNKNOWN',
            $count + 1,
        );
    }
}
