<?php

namespace App\Models\Leave;

use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Something said about a leave request, by either side.
 *
 * Deliberately not the rejection reason. That is a verdict: written once, by
 * the person refusing, and it must not move afterwards. This is a
 * conversation, and the two are stored apart so neither can be mistaken for
 * the other.
 */
class LeaveRequestMessage extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'leave_request_id', 'author_id', 'body', 'visible_to_employee',
    ];

    protected $casts = [
        'visible_to_employee' => 'boolean',
    ];

    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * Everything this user may read on the request.
     *
     * The applicant sees only what was addressed to them; anybody else with
     * access to the request is on the approving side and sees the lot.
     */
    public function scopeVisibleTo($query, bool $isApplicant)
    {
        return $isApplicant ? $query->where('visible_to_employee', true) : $query;
    }
}
