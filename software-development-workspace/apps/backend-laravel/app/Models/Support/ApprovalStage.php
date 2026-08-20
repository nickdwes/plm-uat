<?php

namespace App\Models\Support;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who has to sign off on what, in what order.
 *
 * Follows the travel approval stages already in this system. Two approval
 * conventions in one codebase means two places to look when somebody asks why
 * a thing is stuck.
 */
class ApprovalStage extends Model
{
    use BelongsToOrganization;

    protected $table = 'support_approval_stages';

    public const FOR_CHANGE  = 'change';
    public const FOR_REQUEST = 'service_request';

    protected $fillable = [
        'organization_id', 'stage', 'sequence', 'applies_to',
        'risk', 'support_service_id', 'role_id', 'approver_id', 'is_active',
    ];

    protected $casts = ['sequence' => 'integer', 'is_active' => 'boolean'];

    protected $attributes = ['is_active' => true, 'sequence' => 1];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    /**
     * Whether this stage applies to a given change.
     *
     * An unset condition is ignored rather than treated as a requirement — a
     * stage with only a risk on it should match on risk and nothing else.
     */
    public function appliesToChange(Change $change): bool
    {
        if ($this->applies_to !== self::FOR_CHANGE) {
            return false;
        }

        return $this->risk === null || $this->risk === $change->risk;
    }

    public function appliesToService(?int $serviceId): bool
    {
        if ($this->applies_to !== self::FOR_REQUEST) {
            return false;
        }

        // A stage naming no service covers every service request.
        return $this->support_service_id === null || $this->support_service_id === $serviceId;
    }
}
