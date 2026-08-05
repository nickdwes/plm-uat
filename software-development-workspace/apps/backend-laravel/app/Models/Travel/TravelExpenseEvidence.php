<?php

namespace App\Models\Travel;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A receipt, or a statement that money was received.
 *
 * Both close a line; they are not the same thing. A receipt evidences what was
 * spent, an acknowledgement evidences that an allowance reached the person it
 * was meant for — which is the only check a per diem admits, since there is
 * nothing to produce a document for.
 */
class TravelExpenseEvidence extends Model
{
    use SoftDeletes;

    protected $table = 'travel_expense_evidence';

    public const KINDS = ['receipt', 'acknowledgement'];

    protected $fillable = [
        'travel_request_item_id', 'kind', 'explanation',
        'storage_path', 'original_name', 'mime_type', 'size_bytes',
        'amount', 'submitted_by_employee_id', 'submitted_at',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'submitted_at' => 'datetime',
        'size_bytes'   => 'integer',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(TravelRequestItem::class, 'travel_request_item_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'submitted_by_employee_id');
    }
}
