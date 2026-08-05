<?php

namespace App\Models\Travel;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What happened to a request, and when.
 *
 * Written rather than derived: the interesting events are not all recoverable
 * from current state. A request showing "approved" cannot tell you it was
 * rejected once and resubmitted, and that is usually the question being asked.
 */
class TravelActivity extends Model
{
    protected $table = 'travel_activity';

    protected $fillable = [
        'travel_request_id', 'event', 'description', 'actor_employee_id', 'occurred_at',
    ];

    protected $casts = ['occurred_at' => 'datetime'];

    public function actor(): BelongsTo
    {
        // The person, not the login: a trail that stops making sense once an
        // account is disabled is not a trail.
        return $this->belongsTo(Employee::class, 'actor_employee_id');
    }
}
