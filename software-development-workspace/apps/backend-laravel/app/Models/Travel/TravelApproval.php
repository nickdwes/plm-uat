<?php

namespace App\Models\Travel;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TravelApproval extends Model
{
    protected $fillable = [
        'travel_request_id', 'stage', 'sequence', 'expected_approver_id',
        'decided_by_employee_id', 'status', 'comment', 'decided_at',
    ];

    protected $casts = ['sequence' => 'integer', 'decided_at' => 'datetime'];

    protected $attributes = ['status' => 'pending'];

    public function request(): BelongsTo
    {
        return $this->belongsTo(TravelRequest::class, 'travel_request_id');
    }

    public function expectedApprover(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'expected_approver_id');
    }
}
