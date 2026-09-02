<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The subtractive half of the authorization model.
 *
 * A deny names one user and one permission (possibly a wildcard) and beats
 * every grant — `User::isDenied()` is consulted before any role is. Rows were
 * previously written straight into the table; the model exists so that
 * administering them from the UI leaves the same audit trail a role change
 * does, because a deny is precisely the kind of decision an auditor asks
 * about.
 */
class DenyAssignment extends Model
{
    use \App\Models\Concerns\RecordsAuditTrail;

    protected $fillable = [
        'user_id',
        'permission',
        'organization_id',
        'expires_at',
        'reason',
        'created_by',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The audit label carries the decision, not the person. */
    public function auditName(): string
    {
        return "Deny \"{$this->permission}\" for user #{$this->user_id}";
    }
}
