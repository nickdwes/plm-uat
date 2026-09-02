<?php

namespace App\Http\Controllers\Authorization;

use App\Http\Controllers\Controller;
use App\Models\DenyAssignment;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Per-user denies — the veto that beats every grant.
 *
 * A deny is checked before any role is consulted, so this is the sharpest
 * tool in the drawer: it can close one door for one person ("this contractor,
 * no payroll") or, as a wildcard, act as a soft account-disable. The reason
 * is mandatory because a veto with no recorded why is unanswerable a year
 * later, and wildcards demand an explicit confirmation because their blast
 * radius is easy to underestimate.
 */
class DenyAssignmentController extends Controller
{
    /** Every deny in force or expired, newest first. */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission(Permission::ROLES_VIEW), 403);

        $denies = DenyAssignment::query()
            ->with(['user:id,name,email', 'organization:id,name', 'creator:id,name'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $denies->map(fn (DenyAssignment $deny) => [
                'id'           => $deny->id,
                'user'         => $deny->user?->only(['id', 'name', 'email']),
                'permission'   => $deny->permission,
                'organization' => $deny->organization?->only(['id', 'name']),
                'expires_at'   => $deny->expires_at?->toDateTimeString(),
                'expired'      => $deny->expires_at !== null && $deny->expires_at->isPast(),
                'reason'       => $deny->reason,
                'created_by'   => $deny->creator?->name,
                'created_at'   => $deny->created_at?->toDateTimeString(),
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission(Permission::ROLES_MANAGE), 403);

        $data = $request->validate([
            'user_id'          => ['required', 'integer', 'exists:users,id'],
            'permission'       => ['required', 'string', 'max:100'],
            'organization_id'  => ['nullable', 'integer', 'exists:organizations,id'],
            'expires_at'       => ['nullable', 'date', 'after:now'],
            'reason'           => ['required', 'string', 'max:255'],
            'confirm_wildcard' => ['sometimes', 'boolean'],
        ]);

        $permission = $data['permission'];
        $isWildcard = $permission === '*' || str_ends_with($permission, '.*');

        // `*` (deny everything — a soft disable) is allowed here even though
        // roles may not grant it; resource wildcards must cover something
        // real, exactly as in a role definition.
        if ($permission !== '*' && ! Permission::isWritableGrant($permission)) {
            throw ValidationException::withMessages([
                'permission' => ["\"{$permission}\" is not in the permission catalogue."],
            ]);
        }

        if ($isWildcard && ! ($data['confirm_wildcard'] ?? false)) {
            throw ValidationException::withMessages([
                'confirm_wildcard' => ["A wildcard deny closes every permission it matches — confirm it explicitly."],
            ]);
        }

        /*
         * Not on yourself: a self-deny of roles.manage (or *) is the classic
         * way an administrator locks the whole tenancy out of the controls
         * that could undo it.
         */
        if ((int) $data['user_id'] === $request->user()->getKey()) {
            throw ValidationException::withMessages([
                'user_id' => ['You cannot place a deny on your own account.'],
            ]);
        }

        $deny = DenyAssignment::create([
            'user_id'         => $data['user_id'],
            'permission'      => $permission,
            'organization_id' => $data['organization_id'] ?? null,
            'expires_at'      => $data['expires_at'] ?? null,
            'reason'          => $data['reason'],
            'created_by'      => $request->user()->getKey(),
        ]);

        // The subject's per-request permission cache is theirs, not ours; a
        // deny takes effect on their next request with no action needed here.
        User::find($data['user_id'])?->forgetCachedRoles();

        return response()->json(['data' => ['id' => $deny->id]], 201);
    }

    /** Lift a deny. The audit trail keeps the fact that it existed. */
    public function destroy(Request $request, DenyAssignment $deny): JsonResponse
    {
        abort_unless($request->user()?->hasPermission(Permission::ROLES_MANAGE), 403);

        $deny->delete();

        return response()->json(['message' => 'Deny lifted.']);
    }
}
