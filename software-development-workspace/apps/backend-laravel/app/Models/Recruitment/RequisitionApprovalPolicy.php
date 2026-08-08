<?php

namespace App\Models\Recruitment;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Department;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Who has to agree to a requisition, and in what order.
 *
 * Per department with an organisation-wide fallback. The designs show
 * Project Manager → HR → CTO, which is one policy among several a company will
 * want — engineering headcount and a field technician are not the same
 * decision, and hardcoding one of them means the other is done by email.
 */
class RequisitionApprovalPolicy extends Model
{
    use BelongsToOrganization;

    protected $table = 'requisition_approval_policies';

    protected $fillable = ['organization_id', 'department_id', 'name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(RequisitionApprovalStep::class)->orderBy('position');
    }

    /**
     * The policy governing a department: its own, or the organisation default.
     *
     * Null when neither exists, which the caller must handle rather than
     * silently approving — a requisition nobody has to agree to is a vacancy
     * with extra steps.
     */
    public static function forDepartment(int $organizationId, ?int $departmentId): ?self
    {
        $query = static::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->with('steps');

        if ($departmentId !== null) {
            $own = (clone $query)->where('department_id', $departmentId)->first();

            if ($own) {
                return $own;
            }
        }

        return $query->whereNull('department_id')->first();
    }
}
