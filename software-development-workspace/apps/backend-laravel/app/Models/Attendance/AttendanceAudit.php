<?php

namespace App\Models\Attendance;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One field on one day, changed.
 *
 * Attendance is the record people have the strongest reason to revise after the
 * fact, which is exactly why the revisions have to be visible. Field-level
 * rather than a snapshot, because "the location changed from office to home
 * three days later" is the sentence somebody needs, and diffing two blobs to
 * reach it is how an audit trail goes unread.
 */
class AttendanceAudit extends Model
{
    use BelongsToOrganization;

    protected $table = 'attendance_audits';

    public $timestamps = false;

    protected $fillable = [
        'organization_id', 'attendance_day_id',
        'field', 'from_value', 'to_value', 'changed_by', 'changed_at',
    ];

    protected $casts = ['changed_at' => 'datetime'];

    public function day(): BelongsTo
    {
        return $this->belongsTo(AttendanceDay::class, 'attendance_day_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
