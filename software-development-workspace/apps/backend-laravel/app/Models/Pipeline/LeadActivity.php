<?php

namespace App\Models\Pipeline;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What has happened on this lead.
 *
 * Kept apart from the customer's timeline: "what has happened on this deal" and
 * "when did we last speak to them" are different questions, and a lead that
 * goes quiet while the customer is busy elsewhere is exactly the case worth
 * seeing.
 */
class LeadActivity extends Model
{
    use BelongsToOrganization;

    protected $table = 'lead_activities';

    protected $fillable = [
        'organization_id', 'lead_id', 'kind', 'type', 'body',
        'occurred_at', 'employee_id', 'created_by',
    ];

    protected $casts = ['occurred_at' => 'datetime'];

    protected $attributes = ['kind' => 'manual', 'type' => 'note'];

    public const TYPES = ['note', 'call', 'meeting', 'email', 'demo', 'proposal', 'site_visit'];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
