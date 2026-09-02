<?php

namespace App\Http\Controllers\Authorization;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Support\Authorization\Permission;
use App\Support\Authorization\Scope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Writing role definitions — kept apart from RoleController, which reads
 * them and moves assignments around.
 *
 * The built-in roles are templates: immutable, so a seeder upgrade never
 * fights a local edit, and cloneable, so customising one means owning a copy.
 * Every permission string is validated against the catalogue at write time —
 * the one place a typo can be refused instead of silently granting nothing.
 */
class RoleDefinitionController extends Controller
{
    /** Create a custom role from scratch. */
    public function store(Request $request): JsonResponse
    {
        $this->guard($request);

        $data = $this->validateDefinition($request);

        $role = Role::create([
            ...$data,
            'name'       => $this->uniqueName($data['display_name']),
            'is_system'  => false,
            'created_by' => $request->user()->getKey(),
        ]);

        return response()->json(['data' => ['id' => $role->id]], 201);
    }

    /** Edit a custom role. The change reaches every holder at once. */
    public function update(Request $request, Role $role): JsonResponse
    {
        $this->guard($request);

        if ($role->is_system) {
            return response()->json([
                'message' => 'Built-in roles cannot be edited — clone one to customise it.',
            ], 422);
        }

        // The slug is identity — assignments and audit entries name it — so
        // only the human-facing fields and the permission set may move.
        $role->update($this->validateDefinition($request));

        return response()->json(['message' => 'Role updated.']);
    }

    /**
     * Copy any role — built-in or custom — into a new custom role.
     *
     * This is the intended path for "the HR role, except…": the copy is
     * editable, the template stays exactly what the seeder says it is.
     */
    public function clone(Request $request, Role $role): JsonResponse
    {
        $this->guard($request);

        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:120'],
            'description'  => ['nullable', 'string', 'max:500'],
        ]);

        $copy = Role::create([
            'name'                 => $this->uniqueName($data['display_name']),
            'display_name'         => $data['display_name'],
            'description'          => $data['description'] ?? "Cloned from {$role->display_name}",
            'scope'                => $role->scope,
            'permissions'          => $role->permissions ?? [],
            'excluded_permissions' => $role->excluded_permissions ?? [],
            'assignable_scopes'    => $role->assignable_scopes,
            'is_system'            => false,
            'created_by'           => $request->user()->getKey(),
        ]);

        return response()->json(['data' => ['id' => $copy->id]], 201);
    }

    /** Delete a custom role nobody holds. */
    public function destroy(Request $request, Role $role): JsonResponse
    {
        $this->guard($request);

        if ($role->is_system) {
            return response()->json(['message' => 'Built-in roles cannot be deleted.'], 422);
        }

        $holders = $role->users()
            ->whereNull('user_roles.revoked_at')
            ->where(fn ($q) => $q->whereNull('user_roles.valid_until')->orWhere('user_roles.valid_until', '>', now()))
            ->count();

        if ($holders > 0) {
            return response()->json([
                'message' => "{$holders} " . Str::plural('person', $holders)
                    . ' still hold this role — revoke the assignments first.',
            ], 422);
        }

        $role->delete();

        return response()->json(['message' => 'Role deleted.']);
    }

    /**
     * The shared shape of store and update.
     *
     * Beyond field validation, two semantic checks: every permission string
     * must be writable per the catalogue, and every exclusion must actually
     * be covered by a grant — an exclusion nothing covers is a note that
     * does nothing, and recording it would misrepresent what the role holds.
     */
    private function validateDefinition(Request $request): array
    {
        $data = $request->validate([
            'display_name'           => ['required', 'string', 'max:120'],
            'description'            => ['nullable', 'string', 'max:500'],
            'scope'                  => ['required', 'string', 'in:' . implode(',', Scope::all())],
            'assignable_scopes'      => ['nullable', 'array'],
            'assignable_scopes.*'    => ['string', 'in:' . implode(',', Scope::all())],
            'permissions'            => ['present', 'array'],
            'permissions.*'          => ['string', 'max:100'],
            'excluded_permissions'   => ['present', 'array'],
            'excluded_permissions.*' => ['string', 'max:100'],
        ]);

        foreach ($data['permissions'] as $permission) {
            if (! Permission::isWritableGrant($permission)) {
                throw ValidationException::withMessages([
                    'permissions' => ["\"{$permission}\" is not in the permission catalogue."],
                ]);
            }
        }

        foreach ($data['excluded_permissions'] as $exclusion) {
            if (! Permission::isKnown($exclusion)) {
                throw ValidationException::withMessages([
                    'excluded_permissions' => ["\"{$exclusion}\" is not a concrete catalogue permission."],
                ]);
            }

            if (! Permission::anySatisfies($data['permissions'], $exclusion)) {
                throw ValidationException::withMessages([
                    'excluded_permissions' => ["\"{$exclusion}\" is excluded but nothing grants it — remove the exclusion or grant a wildcard that covers it."],
                ]);
            }
        }

        return [
            'display_name'         => $data['display_name'],
            'description'          => $data['description'] ?? null,
            'scope'                => $data['scope'],
            'assignable_scopes'    => $data['assignable_scopes'] ?? null,
            'permissions'          => array_values(array_unique($data['permissions'])),
            'excluded_permissions' => array_values(array_unique($data['excluded_permissions'])),
        ];
    }

    /** A stable slug from the display name, suffixed if somebody reused one. */
    private function uniqueName(string $displayName): string
    {
        $base = Str::slug($displayName, '_') ?: 'custom_role';
        $name = $base;

        for ($i = 2; Role::where('name', $name)->exists(); $i++) {
            $name = "{$base}_{$i}";
        }

        return $name;
    }

    private function guard(Request $request): void
    {
        abort_unless($request->user()?->hasPermission(Permission::ROLES_MANAGE), 403);
    }
}
