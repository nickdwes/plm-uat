<?php

namespace App\Services\Authorization;

use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permission;
use App\Support\Authorization\Scope;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Granting and revoking role assignments.
 *
 * An assignment is (identity, role, tenant, scope, validity). The tenant
 * dimension is what lets one person be an administrator in one organization
 * and an ordinary employee in another — the same identity, different rights,
 * with no second account.
 */
class RoleAssignmentService
{
    /**
     * Every assignment held by an identity, across all tenants.
     *
     * Revoked and lapsed rows are included and marked rather than filtered.
     * "Who had access in March" is the question this table exists to answer,
     * and a filtered list cannot answer it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forUser(User $user): array
    {
        return DB::table('user_roles as ur')
            ->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->leftJoin('organizations as o', 'o.id', '=', 'ur.organization_id')
            ->leftJoin('users as g', 'g.id', '=', 'ur.granted_by')
            ->leftJoin('users as rv', 'rv.id', '=', 'ur.revoked_by')
            ->where('ur.user_id', $user->getKey())
            ->orderBy('o.name')
            ->orderBy('r.name')
            ->get([
                'ur.id', 'ur.scope', 'ur.valid_from', 'ur.valid_until',
                'ur.revoked_at', 'ur.grant_reason',
                'r.id as role_id', 'r.name as role_name', 'r.display_name as role_display_name',
                'r.is_system', 'r.permissions',
                'o.id as organization_id', 'o.name as organization_name', 'o.slug as organization_slug',
                'g.name as granted_by_name', 'rv.name as revoked_by_name',
            ])
            ->map(fn ($row) => [
                'id'            => $row->id,
                'role'          => [
                    'id'           => $row->role_id,
                    'name'         => $row->role_name,
                    'display_name' => $row->role_display_name,
                    'is_system'    => (bool) $row->is_system,
                    'permissions'  => json_decode($row->permissions ?? '[]', true) ?: [],
                ],
                'organization'  => $row->organization_id === null ? null : [
                    'id'   => $row->organization_id,
                    'name' => $row->organization_name,
                    'slug' => $row->organization_slug,
                ],
                'scope'         => $row->scope,
                'valid_from'    => $row->valid_from,
                'valid_until'   => $row->valid_until,
                'revoked_at'    => $row->revoked_at,
                'grant_reason'  => $row->grant_reason,
                'granted_by'    => $row->granted_by_name,
                'revoked_by'    => $row->revoked_by_name,
                'state'         => $this->stateOf($row),
            ])
            ->all();
    }

    /** Everyone holding a given role, and where. */
    public function holdersOf(Role $role): array
    {
        return DB::table('user_roles as ur')
            ->join('users as u', 'u.id', '=', 'ur.user_id')
            ->leftJoin('organizations as o', 'o.id', '=', 'ur.organization_id')
            ->where('ur.role_id', $role->getKey())
            ->orderBy('u.name')
            ->get([
                'ur.id', 'ur.scope', 'ur.valid_from', 'ur.valid_until', 'ur.revoked_at',
                'u.id as user_id', 'u.name as user_name', 'u.email', 'u.account_status',
                'o.id as organization_id', 'o.name as organization_name',
            ])
            ->map(fn ($row) => [
                'id'           => $row->id,
                'user'         => [
                    'id'             => $row->user_id,
                    'name'           => $row->user_name,
                    'email'          => $row->email,
                    'account_status' => $row->account_status,
                ],
                'organization' => $row->organization_id === null ? null : [
                    'id' => $row->organization_id, 'name' => $row->organization_name,
                ],
                'scope'       => $row->scope,
                'valid_from'  => $row->valid_from,
                'valid_until' => $row->valid_until,
                'state'       => $this->stateOf($row),
            ])
            ->all();
    }

    /**
     * Grant a role to an identity in one tenant.
     *
     * @throws RuntimeException when the grant would exceed the granter's own rights
     */
    public function grant(
        User $granter,
        User $subject,
        Role $role,
        ?int $organizationId,
        string $scope = Scope::ORGANIZATION,
        ?string $validUntil = null,
        ?string $reason = null,
    ): int {
        $this->assertMayGrant($granter, $role, $organizationId, $scope);

        return DB::transaction(function () use ($granter, $subject, $role, $organizationId, $scope, $validUntil, $reason) {
            // Re-granting something already effective is a no-op rather than a
            // duplicate row, which would otherwise make revocation ambiguous.
            $existing = DB::table('user_roles')
                ->where('user_id', $subject->getKey())
                ->where('role_id', $role->getKey())
                ->where('organization_id', $organizationId)
                ->whereNull('revoked_at')
                ->first();

            if ($existing) {
                return (int) $existing->id;
            }

            $id = (int) DB::table('user_roles')->insertGetId([
                'user_id'         => $subject->getKey(),
                'role_id'         => $role->getKey(),
                'organization_id' => $organizationId,
                'scope'           => $scope,
                'valid_from'      => now(),
                'valid_until'     => $validUntil,
                'granted_by'      => $granter->getKey(),
                'grant_reason'    => $reason,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            // Handing somebody a role is a privilege change — the trail an
            // auditor reaches for first.
            \App\Services\Audit\AuditLogger::record(
                action: 'role_granted',
                auditable: $subject,
                description: "Granted role \"{$role->name}\" to {$subject->name}" . ($reason ? " — {$reason}" : ''),
                organizationId: $organizationId,
                userId: $granter->getKey(), actorName: $granter->name,
            );

            return $id;
        });
    }

    /**
     * Revoke an assignment.
     *
     * Marked, never deleted. A deleted row cannot answer what someone could do
     * last quarter, which is the question an audit actually asks.
     */
    public function revoke(User $revoker, int $assignmentId, ?string $reason = null): bool
    {
        $assignment = DB::table('user_roles')->where('id', $assignmentId)->whereNull('revoked_at')->first();

        $updated = DB::table('user_roles')
            ->where('id', $assignmentId)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at'   => now(),
                'revoked_by'   => $revoker->getKey(),
                'grant_reason' => $reason ?: DB::raw('grant_reason'),
                'updated_at'   => now(),
            ]);

        if ($updated > 0 && $assignment) {
            $roleName = DB::table('roles')->where('id', $assignment->role_id)->value('name');
            \App\Services\Audit\AuditLogger::record(
                action: 'role_revoked',
                description: "Revoked role \"{$roleName}\" from user #{$assignment->user_id}" . ($reason ? " — {$reason}" : ''),
                organizationId: $assignment->organization_id,
                userId: $revoker->getKey(), actorName: $revoker->name,
            );
        }

        return $updated > 0;
    }

    /**
     * No privilege escalation.
     *
     * A granter may only hand out permissions they hold themselves, at a scope
     * no wider than their own. Without this, anyone able to assign roles is
     * effectively a group administrator — they need only grant themselves a
     * role that is.
     *
     * @throws RuntimeException
     */
    private function assertMayGrant(User $granter, Role $role, ?int $organizationId, string $scope): void
    {
        if (! Scope::isValid($scope)) {
            throw new RuntimeException("Unknown scope [{$scope}].");
        }

        if (! $role->isAssignableAt($scope)) {
            throw new RuntimeException(
                "The role [{$role->name}] may not be assigned at scope [{$scope}]."
            );
        }

        // A group-wide grant requires group-wide authority.
        if ($organizationId === null && ! $granter->hasPermission(Permission::ROLES_ASSIGN, null)) {
            throw new RuntimeException('Granting group-wide requires group-level authority.');
        }

        foreach ($role->permissions ?? [] as $permission) {
            // Wildcards are checked by their concrete members, so granting
            // `payroll.*` requires holding every payroll permission rather than
            // any one of them.
            foreach ($this->expand($permission) as $concrete) {
                $held = $granter->scopeForPermission($concrete, $organizationId);

                if ($held === null) {
                    throw new RuntimeException(
                        "You cannot grant [{$concrete}] because you do not hold it."
                    );
                }

                if (! Scope::covers($held, $scope)) {
                    throw new RuntimeException(
                        "You cannot grant [{$concrete}] at scope [{$scope}]; yours reaches only [{$held}]."
                    );
                }
            }
        }
    }

    /**
     * Expand a wildcard into the concrete permissions it covers.
     *
     * @return string[]
     */
    private function expand(string $permission): array
    {
        if (! str_contains($permission, '*')) {
            return [$permission];
        }

        return array_values(array_filter(
            Permission::all(),
            fn (string $p) => Permission::satisfies($permission, $p)
        ));
    }

    private function stateOf(object $row): string
    {
        if ($row->revoked_at !== null) {
            return 'revoked';
        }

        if ($row->valid_until !== null && $row->valid_until <= now()->toDateTimeString()) {
            return 'lapsed';
        }

        if ($row->valid_from !== null && $row->valid_from > now()->toDateTimeString()) {
            return 'scheduled';
        }

        return 'active';
    }
}
