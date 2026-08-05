<?php

namespace App\Models\Delivery;

use App\Models\Employee;
use App\Models\Travel\TravelRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone delivering the consignment.
 *
 * More than one, because a heavy load travels with a driver and a hand, and
 * both need naming for a note either of them might sign.
 */
class DeliveryCourier extends Model
{
    protected $fillable = ['travel_request_id', 'employee_id', 'is_primary'];

    protected $casts = ['is_primary' => 'boolean'];

    protected $attributes = ['is_primary' => false];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(TravelRequest::class, 'travel_request_id');
    }
}
