<?php

namespace App\Http\Controllers\Authorization;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\Authorization\RoleAssignmentService;
use App\Support\Authorization\Permission;
use App\Support\Authorization\Scope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Authorization administration.
 *
 * Role-centric, like the Entra role assignments screen, with one addition
 * that model does not have: every assignment names a tenant, so the same
 * identity holds different rights in different organizations.
 */
class RoleController extends Controller
{
    public function __construct(private readonly RoleAssignmentService $assignments) {}

    /** Roles, with a count of who holds each. */
    public function index(Request $request): JsonResponse
    {
        $this->guard($request, Permission::ROLES_VIEW);

        $roles = Role::query()
            ->withCount(['users as holders_count' => fn ($q) => $q->whereNull('user_roles.revoked_at')])
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $roles->map(fn (Role $role) => [
                'id'                   => $role->id,
                'name'                 => $role->name,
                'display_name'         => $role->display_name,
                'description'          => $role->description,
                'scope'                => $role->scope,
                'is_system'            => (bool) $role->is_system,
                'permissions'          => $role->permissions ?? [],
                'excluded_permissions' => $role->excluded_permissions ?? [],
                'assignable_scopes'    => $role->assignable_scopes,
                'holders_count'        => $role->holders_count,
            ]),
        ]);
    }

    /**
     * One role: its permissions grouped by resource, and who holds it where.
     *
     * Permissions are returned grouped rather than flat because a list of
     * ninety strings is not reviewable — the question being asked is "what can
     * this role do to payroll", not "is this exact string present".
     */
    public function show(Request $request, Role $role): JsonResponse
    {
        $this->guard($request, Permission::ROLES_VIEW);

        $granted = $role->permissions ?? [];
        $excluded = $role->excluded_permissions ?? [];

        $grouped = [];

        foreach (Permission::grouped() as $resource => $permissions) {
            $entries = [];

            foreach ($permissions as $permission) {
                $isGranted  = Permission::anySatisfies($granted, $permission);
                $isExcluded = Permission::anySatisfies($excluded, $permission);

                $entries[] = [
                    'permission' => $permission,
                    // Excluded beats granted, matching how the check resolves.
                    'state'      => $isExcluded ? 'excluded' : ($isGranted ? 'granted' : 'not_granted'),
                ];
            }

            $grouped[] = [
                'resource'      => $resource,
                'permissions'   => $entries,
                'granted_count' => count(array_filter($entries, fn ($e) => $e['state'] === 'granted')),
                'total'         => count($entries),
            ];
        }

        return response()->json([
            'data' => [
                'id'                   => $role->id,
                'name'                 => $role->name,
                'display_name'         => $role->display_name,
                'description'          => $role->description,
                'scope'                => $role->scope,
                'is_system'            => (bool) $role->is_system,
                'permissions'          => $granted,
                'excluded_permissions' => $excluded,
                'assignable_scopes'    => $role->assignable_scopes ?? Scope::all(),
                'permission_groups'    => $grouped,
                'holders'              => $this->assignments->holdersOf($role),
            ],
        ]);
    }

    /** Every assignment an identity holds, across every tenant. */
    public function assignmentsFor(Request $request, User $user): JsonResponse
    {
        $this->guard($request, Permission::ROLES_VIEW);

        return response()->json([
            'data' => [
                'user' => [
                    'id'             => $user->id,
                    'name'           => $user->name,
                    'email'          => $user->email,
                    'account_status' => $user->account_status,
                ],
                'assignments' => $this->assignments->forUser($user),
            ],
        ]);
    }

    /** Grant a role to an identity in one tenant. */
    public function grant(Request $request): JsonResponse
    {
        $this->guard($request, Permission::ROLES_ASSIGN);

        $data = $request->validate([
            'user_id'         => ['required', 'integer', 'exists:users,id'],
            'role_id'         => ['required', 'integer', 'exists:roles,id'],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'scope'           => ['required', 'string', 'in:' . implode(',', Scope::all())],
            'valid_until'     => ['nullable', 'date', 'after:now'],
            'reason'          => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $id = $this->assignments->grant(
                granter: $request->user(),
                subject: User::findOrFail($data['user_id']),
                role: Role::findOrFail($data['role_id']),
                organizationId: $data['organization_id'] ?? null,
                scope: $data['scope'],
                validUntil: $data['valid_until'] ?? null,
                reason: $data['reason'] ?? null,
            );
        } catch (RuntimeException $e) {
            // 422 rather than 403: the caller may assign roles, just not this
            // one. The distinction matters to whoever reads the message.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['id' => $id]], 201);
    }

    /** Revoke an assignment. Marked, not deleted. */
    public function revoke(Request $request, int $assignment): JsonResponse
    {
        $this->guard($request, Permission::ROLES_ASSIGN);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        $done = $this->assignments->revoke($request->user(), $assignment, $data['reason'] ?? null);

        abort_unless($done, 404, 'No effective assignment with that id.');

        return response()->json(['message' => 'Assignment revoked.']);
    }

    /** Tenants and scopes the UI needs to offer a grant. */
    public function options(Request $request): JsonResponse
    {
        $this->guard($request, Permission::ROLES_VIEW);

        return response()->json([
            'data' => [
                'organizations' => Organization::orderBy('name')->get(['id', 'name', 'slug']),
                'scopes'        => array_map(fn ($s) => [
                    'value' => $s,
                    'label' => ucfirst($s),
                ], Scope::all()),
                'resources'     => array_keys(Permission::grouped()),
            ],
        ]);
    }

    private function guard(Request $request, string $permission): void
    {
        abort_unless($request->user()?->hasPermission($permission), 403);
    }
}
