<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person moving between organisations, written down.
 *
 * @see database/migrations/2026_08_23_090000_record_when_people_move_between_organisations.php
 */
class EmployeeMovement extends Model
{
    public const SECONDMENT = 'secondment';
    public const TRANSFER   = 'transfer';
    public const RETURN     = 'return';

    public const KINDS = [self::SECONDMENT, self::TRANSFER, self::RETURN];

    protected $fillable = [
        'employee_id', 'from_organization_id', 'to_organization_id',
        'kind', 'effective_on', 'reason', 'moved_by', 'preflight', 'carried',
    ];

    protected $casts = [
        'effective_on' => 'date',
        'preflight'    => 'array',
        'carried'      => 'array',
    ];

    public function employee(): BelongsTo   { return $this->belongsTo(Employee::class); }
    public function from(): BelongsTo       { return $this->belongsTo(Organization::class, 'from_organization_id'); }
    public function to(): BelongsTo         { return $this->belongsTo(Organization::class, 'to_organization_id'); }
    public function movedBy(): BelongsTo    { return $this->belongsTo(User::class, 'moved_by'); }
}
