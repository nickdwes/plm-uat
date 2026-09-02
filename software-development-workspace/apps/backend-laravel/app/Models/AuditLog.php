<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One entry in the central audit trail.
 *
 * Deliberately NOT using BelongsToOrganization: security events (failed logins,
 * cross-tenant denials) carry a null organization_id and must remain visible to
 * a group administrator, so scoping is applied explicitly in the controller
 * rather than by a global scope that would hide exactly those rows.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null; // append-only; created_at only.

    protected $table = 'audit_logs';

    protected $fillable = [
        'organization_id', 'user_id', 'actor_name', 'action',
        'auditable_type', 'auditable_id', 'description', 'changes',
        'ip_address', 'user_agent', 'route',
    ];

    protected $casts = [
        'changes'    => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
