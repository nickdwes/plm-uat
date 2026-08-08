<?php

namespace App\Models\Recruitment;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step of the timeline down the right of the detail screen.
 *
 * Rows rather than a set of timestamp columns, because the designs show the
 * timeline truncating on hold and resuming afterwards — the same stage can be
 * reached twice, which a column cannot express. "Held twice before approval" is
 * exactly what somebody reviewing a slow hire needs to see.
 */
class RequisitionEvent extends Model
{
    use BelongsToOrganization;

    protected $table = 'requisition_events';

    public $timestamps = false;

    protected $fillable = [
        'organization_id', 'talent_requisition_id',
        'status', 'note', 'actor_employee_id', 'occurred_at',
    ];

    protected $casts = ['occurred_at' => 'datetime'];

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(TalentRequisition::class, 'talent_requisition_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'actor_employee_id');
    }
}
