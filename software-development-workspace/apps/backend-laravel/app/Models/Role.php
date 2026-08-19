<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'display_name',
        'description',
        'scope',
        'is_system',
        'permissions',
        'excluded_permissions',
        'assignable_scopes',
        'organization_id',
        'created_by',
    ];

    protected $casts = [
        'permissions'          => 'array',
        'excluded_permissions' => 'array',
        'assignable_scopes'    => 'array',
        'is_system'            => 'boolean',
    ];

    /**
     * May this definition be assigned at the given scope?
     *
     * A null `assignable_scopes` means unrestricted, which is correct for the
     * built-in roles. A custom role built for departmental use should list
     * only the scopes it is meant for, so it cannot later be granted
     * tenant-wide whatever an administrator intends.
     */
    public function isAssignableAt(string $scope): bool
    {
        if (! \App\Support\Authorization\Scope::isValid($scope)) {
            return false;
        }

        if (empty($this->assignable_scopes)) {
            return true;
        }

        return in_array($scope, $this->assignable_scopes, true);
    }

    // Role name constants — use these everywhere instead of raw strings
    const GROUP_ADMIN  = 'group_admin';
    const ORG_HR       = 'org_hr';
    const DEPT_MANAGER = 'dept_manager';
    const EMPLOYEE     = 'employee';

    /*
     * The service desk. Two roles, because they are two jobs: the analyst
     * places, chases and escalates work without owning the fix, and the
     * engineer owns the fix without deciding whose queue things land in.
     */
    const SERVICE_DESK_ANALYST = 'service_desk_analyst';
    const SUPPORT_ENGINEER     = 'support_engineer';

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles')
            ->withPivot('organization_id')
            ->withTimestamps();
    }
}
