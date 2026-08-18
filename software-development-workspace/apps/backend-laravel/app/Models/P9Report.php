<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The state of one year's P9 exercise.
 *
 * Holds no figures. Those stay computed from the payroll runs, because a P9
 * carrying its own copy would eventually disagree with the payslips it was
 * built from — and the P9 is the document that goes to KRA.
 */
class P9Report extends Model
{
    use BelongsToOrganization;

    protected $table = 'payroll_p9_reports';

    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED   = 'completed';

    protected $fillable = [
        'organization_id', 'tax_year', 'reference', 'status',
        'employee_count', 'generated_at', 'generated_by',
        'completed_at', 'completed_by',
    ];

    protected $casts = [
        'tax_year'       => 'integer',
        'employee_count' => 'integer',
        'generated_at'   => 'datetime',
        'completed_at'   => 'datetime',
    ];

    protected $attributes = ['status' => self::STATUS_IN_PROGRESS];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * The record for a year, created on first sight.
     *
     * Opening last year's P9 should produce one rather than an empty screen:
     * the exercise exists whether or not anybody has pressed anything.
     */
    public static function forYear(int $organizationId, int $year): self
    {
        return static::withoutGlobalScope('organization')->firstOrCreate(
            ['organization_id' => $organizationId, 'tax_year' => $year],
            ['reference' => sprintf('P9-%d-%04d', $year, $organizationId)],
        );
    }
}
