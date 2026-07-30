<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\Authorization\Permission;
use App\Support\Authorization\ResourceAttributes;
use App\Support\Authorization\Scope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\DB;
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
            ->withPivot([
                'organization_id', 'scope',
                'valid_from', 'valid_until',
                'revoked_at', 'revoked_by', 'granted_by', 'grant_reason',
            ])
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

        $now = now();

        return $this->cachedRoleAssignments = $this->roles()
            ->get(['roles.id', 'roles.name', 'roles.permissions', 'roles.excluded_permissions'])
            ->map(function ($role) {
                $pivot = $role->pivot;

                // A grant is effective only inside its validity window and
                // only while unrevoked. Revoked rows are retained so that
                // "who had access last March" stays answerable, which means
                // they must be filtered here rather than deleted there.
                $revoked = $pivot->revoked_at !== null;
                $notYet  = $pivot->valid_from !== null && $pivot->valid_from > now();
                $lapsed  = $pivot->valid_until !== null && $pivot->valid_until <= now();

                return [
                    'name'            => $role->name,
                    'organization_id' => $pivot->organization_id !== null
                        ? (int) $pivot->organization_id
                        : null,
                    'scope'           => $pivot->scope ?? Scope::ORGANIZATION,
                    'permissions'     => $role->permissions ?? [],
                    'excluded'        => $role->excluded_permissions ?? [],
                    'effective'       => ! ($revoked || $notYet || $lapsed),
                ];
            })
            ->all();
    }

    /**
     * Assignments that currently grant something. Revoked, lapsed and
     * not-yet-effective grants are excluded.
     *
     * @return array<int, array>
     */
    protected function effectiveAssignments(): array
    {
        return array_values(array_filter(
            $this->roleAssignments(),
            fn (array $a) => $a['effective'] === true
        ));
    }

    /** @var array<int, array>|null */
    protected ?array $cachedRoleAssignments = null;

    /**
     * Drop all memoized authorization state — role assignments and deny
     * assignments both.
     *
     * Call after granting, revoking or denying within the same request.
     * Clearing only the roles half would leave a stale deny cache, which
     * fails *open* for a newly added denial — the dangerous direction.
     */
    public function forgetCachedRoles(): static
    {
        $this->cachedRoleAssignments = null;
        $this->cachedDenyAssignments = null;

        return $this;
    }

    /**
     * The raw permission strings granted in an organization, wildcards
     * included. Group-scoped assignments apply everywhere, so they are always
     * included alongside the org-scoped ones.
     *
     * Exclusions are deliberately NOT applied here. An exclusion can only be
     * resolved against a specific permission — `leave.approve` cannot be
     * subtracted from the string `leave.*` by filtering a list, because the
     * wildcard is what carries the grant. Use hasPermission() for a decision;
     * this is for display and inspection.
     *
     * @return string[]
     */
    public function permissionsFor(?int $organizationId = null): array
    {
        $granted = [];

        foreach ($this->effectiveAssignments() as $assignment) {
            if (! $this->assignmentAppliesIn($assignment, $organizationId)) {
                continue;
            }

            $granted = array_merge($granted, $assignment['permissions']);
        }

        return array_values(array_unique($granted));
    }

    /** Does an assignment apply in the organization being asked about? */
    protected function assignmentAppliesIn(array $assignment, ?int $organizationId): bool
    {
        // Group scope reaches every tenant.
        if ($assignment['organization_id'] === null || $assignment['scope'] === Scope::GROUP) {
            return true;
        }

        return $organizationId !== null
            && $assignment['organization_id'] === $organizationId;
    }

    /**
     * The widest scope at which this user holds a permission, or null if they
     * do not hold it at all.
     *
     * This is the RBAC half of a scoped check: it answers "how far does this
     * grant reach", leaving ResourceAttributes to answer whether a particular
     * record falls inside that reach.
     */
    public function scopeForPermission(string $permission, ?int $organizationId = null): ?string
    {
        Permission::assertKnown($permission);

        if (! $this->isAccountActive() || $this->isDenied($permission, $organizationId)) {
            return null;
        }

        $organizationId ??= app()->bound('current.organization_id')
            ? app('current.organization_id')
            : null;

        $scopes = [];

        foreach ($this->effectiveAssignments() as $assignment) {
            if (! $this->assignmentAppliesIn($assignment, $organizationId)) {
                continue;
            }

            if (Permission::anySatisfies($assignment['excluded'], $permission)) {
                continue;
            }

            if (Permission::anySatisfies($assignment['permissions'], $permission)) {
                $scopes[] = $assignment['scope'];
            }
        }

        return Scope::widest($scopes);
    }

    /**
     * Is this permission explicitly denied?
     *
     * Deny is the subtractive half of an otherwise additive model, and is
     * evaluated before any grant. Roles cannot express "everything except X";
     * this can.
     */
    public function isDenied(string $permission, ?int $organizationId = null): bool
    {
        $organizationId ??= app()->bound('current.organization_id')
            ? app('current.organization_id')
            : null;

        foreach ($this->denyAssignments() as $deny) {
            // A denial with a null organization applies in every tenant.
            if ($deny['organization_id'] !== null
                && $deny['organization_id'] !== $organizationId) {
                continue;
            }

            if (Permission::satisfies($deny['permission'], $permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Deny assignments in force right now, memoized per request.
     *
     * @return array<int, array{permission: string, organization_id: int|null}>
     */
    protected function denyAssignments(): array
    {
        if ($this->cachedDenyAssignments !== null) {
            return $this->cachedDenyAssignments;
        }

        // No tolerance for the table being missing. An earlier version fell
        // back to "no denials" if it was absent, which is a fail-open: the
        // one direction authorization must never fail in. A missing table is
        // a broken deployment and should surface as an error, not as silently
        // granted access.
        return $this->cachedDenyAssignments = DB::table('deny_assignments')
            ->where('user_id', $this->getKey())
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get(['permission', 'organization_id'])
            ->map(fn ($row) => [
                'permission'      => $row->permission,
                'organization_id' => $row->organization_id !== null ? (int) $row->organization_id : null,
            ])
            ->all();
    }

    /** @var array<int, array>|null */
    protected ?array $cachedDenyAssignments = null;

    /**
     * Does this user hold a permission, honouring wildcards?
     *
     * Defaults to the organization currently in context. This answers the
     * RBAC half only — where a permission is scoped to a specific record
     * (own, or within a reporting line), the caller must also apply the
     * matching check from ResourceAttributes.
     */
    /**
     * The full decision: account active, not explicitly denied, and granted
     * by some effective assignment.
     *
     * Delegates to scopeForPermission() so the evaluation order lives in one
     * place. A permission held at any scope is held; whether that scope
     * reaches a particular record is the separate question ResourceAttributes
     * answers.
     */
    public function hasPermission(string $permission, ?int $organizationId = null): bool
    {
        return $this->scopeForPermission($permission, $organizationId) !== null;
    }

    /**
     * Can this account exercise any permission at all?
     *
     * Only `active` qualifies. `pending` covers a person who exists but has
     * not started, `suspended` a temporary block, `disabled` a leaver — none
     * of which should authorise anything.
     */
    public function isAccountActive(): bool
    {
        // Defaults to active when the column is absent, so the check is safe
        // to deploy before the migration runs.
        return ($this->account_status ?? self::STATUS_ACTIVE) === self::STATUS_ACTIVE;
    }

    public const STATUS_PENDING   = 'pending';
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_DISABLED  = 'disabled';

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
