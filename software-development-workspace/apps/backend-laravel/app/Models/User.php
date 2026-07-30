<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\Authorization\Permission;
use App\Support\Authorization\ResourceAttributes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'azure_id',
        'sso_only',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    /**
     * All role assignments (may span multiple organizations).
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')
            ->withPivot('organization_id')
            ->withTimestamps();
    }

    // ── RBAC Helpers ──────────────────────────────────────────────────────────

    /**
     * Check if the user holds a role, optionally scoped to an organization.
     * Passing null for $organizationId checks for group-level (scope = group) roles.
     */
    public function hasRole(string $roleName, ?int $organizationId = null): bool
    {
        foreach ($this->roleAssignments() as $assignment) {
            if ($assignment['name'] === $roleName
                && $assignment['organization_id'] === $organizationId) {
                return true;
            }
        }

        return false;
    }

    public function isGroupAdmin(): bool
    {
        return $this->hasRole(Role::GROUP_ADMIN);
    }

    public function isOrgHR(?int $organizationId = null): bool
    {
        if ($this->isGroupAdmin()) {
            return true;
        }

        return $this->hasRole(Role::ORG_HR, $organizationId);
    }

    public function isManagerIn(?int $organizationId = null): bool
    {
        if ($this->isOrgHR($organizationId)) {
            return true;
        }

        return $this->hasRole(Role::DEPT_MANAGER, $organizationId);
    }

    /**
     * Return all organization IDs this user has any role in.
     */
    public function accessibleOrganizationIds(): array
    {
        if ($this->isGroupAdmin()) {
            return Organization::pluck('id')->toArray();
        }

        return $this->roles()
            ->whereNotNull('user_roles.organization_id')
            ->pluck('user_roles.organization_id')
            ->unique()
            ->values()
            ->toArray();
    }

    // ── Permissions (RBAC) ────────────────────────────────────────────────────

    /**
     * Role assignments for this user, loaded once per request.
     *
     * Every role helper previously issued its own query, and they nest —
     * isManagerIn() falls through to isOrgHR() which falls through to
     * isGroupAdmin(), so a single check cost three round trips, repeated at
     * each of the ~120 authorization call sites. Resolving the full set once
     * makes every subsequent check an in-memory scan.
     *
     * @return array<int, array{name: string, organization_id: int|null, permissions: array}>
     */
    protected function roleAssignments(): array
    {
        if ($this->cachedRoleAssignments !== null) {
            return $this->cachedRoleAssignments;
        }

        return $this->cachedRoleAssignments = $this->roles()
            ->get(['roles.id', 'roles.name', 'roles.permissions'])
            ->map(fn ($role) => [
                'name'            => $role->name,
                'organization_id' => $role->pivot->organization_id !== null
                    ? (int) $role->pivot->organization_id
                    : null,
                'permissions'     => $role->permissions ?? [],
            ])
            ->all();
    }

    /** @var array<int, array>|null */
    protected ?array $cachedRoleAssignments = null;

    /**
     * Drop the memoized assignments. Call after granting or revoking a role
     * within the same request, otherwise the stale set is reused.
     */
    public function forgetCachedRoles(): static
    {
        $this->cachedRoleAssignments = null;

        return $this;
    }

    /**
     * Every permission this user holds in the given organization.
     *
     * Group-scoped assignments (organization_id === null) apply everywhere,
     * so they are always included alongside the org-scoped ones.
     *
     * @return string[]
     */
    public function permissionsFor(?int $organizationId = null): array
    {
        $granted = [];

        foreach ($this->roleAssignments() as $assignment) {
            $isGroupScoped = $assignment['organization_id'] === null;
            $matchesOrg    = $organizationId !== null
                && $assignment['organization_id'] === $organizationId;

            if ($isGroupScoped || $matchesOrg) {
                $granted = array_merge($granted, $assignment['permissions']);
            }
        }

        return array_values(array_unique($granted));
    }

    /**
     * Does this user hold a permission, honouring wildcards?
     *
     * Defaults to the organization currently in context. This answers the
     * RBAC half only — where a permission is scoped to a specific record
     * (own, or within a reporting line), the caller must also apply the
     * matching check from ResourceAttributes.
     */
    public function hasPermission(string $permission, ?int $organizationId = null): bool
    {
        Permission::assertKnown($permission);

        $organizationId ??= app()->bound('current.organization_id')
            ? app('current.organization_id')
            : null;

        return Permission::anySatisfies($this->permissionsFor($organizationId), $permission);
    }

    /** Does this user hold every one of these permissions? */
    public function hasAllPermissions(string ...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if (! $this->hasPermission($permission)) {
                return false;
            }
        }

        return true;
    }

    /** Does this user hold at least one of these permissions? */
    public function hasAnyPermission(string ...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    // ── Attributes (ABAC) ─────────────────────────────────────────────────────

    /** This user's employee record id, or null if none is linked. */
    public function employeeId(): ?int
    {
        if ($this->cachedEmployeeId === false) {
            $this->cachedEmployeeId = $this->employee()->value('id');
        }

        return $this->cachedEmployeeId !== null ? (int) $this->cachedEmployeeId : null;
    }

    /** @var int|null|false false means "not yet resolved" */
    protected int|null|false $cachedEmployeeId = false;

    /** Is this record the user's own? */
    public function owns(?Model $resource): bool
    {
        return ResourceAttributes::isOwnedBy($this, $resource);
    }

    /** Is this employee in the user's reporting line, at any depth? */
    public function manages(?Model $employee): bool
    {
        return ResourceAttributes::reportsTo($this, $employee);
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
        ];
    }
}
