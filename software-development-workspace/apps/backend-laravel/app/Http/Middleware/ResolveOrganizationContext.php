<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active organization context for each API request.
 *
 * Clients (web SPA or mobile) signal which organization context they are
 * operating in via the X-Organization header (slug or numeric ID).
 *
 * Behavior:
 *   - Group Admins may pass any org, or omit the header to operate group-wide.
 *   - Org HR / Dept Managers must pass a header for an org they have access to.
 *   - Employees must pass a header for an org they belong to.
 *   - If no header is set and the user has exactly one org, that org is auto-resolved.
 *
 * The resolved organization_id is bound into the service container as
 * 'current.organization_id' (null = group-wide / no filter applied).
 * The resolved Organization model is bound as 'current.organization'.
 */
class ResolveOrganizationContext
{
    /**
     * The container key is `current.organization_id`.
     *
     * Written down because thirteen call sites across notifications and
     * training reached for `app('organizationId')` — a name nothing has ever
     * bound. Every one of them threw a BindingResolutionException, so the
     * notifications bell and every training assignment endpoint returned a 500
     * for their entire existence, and the failure looked like a server problem
     * rather than a typo.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            // Unauthenticated — bind null; auth middleware will reject if needed
            app()->instance('current.organization_id', null);
            app()->instance('current.organization', null);

            return $next($request);
        }

        // Anyone holding an assignment at group scope operates across all orgs;
        // null means no filter. Decided by scope rather than by role name, so a
        // custom role granted group-wide is recognised as such.
        if ($user->hasGroupScope()) {
            $orgId = $this->resolveFromHeader($request);
            app()->instance('current.organization_id', $orgId);
            app()->instance('current.organization', $orgId ? Organization::find($orgId) : null);

            return $next($request);
        }

        // For all other roles, resolve the org from the header
        $orgId = $this->resolveFromHeader($request);

        if ($orgId === null) {
            // Auto-resolve if user has exactly one accessible org
            $accessible = $user->accessibleOrganizationIds();
            if (count($accessible) === 1) {
                $orgId = $accessible[0];
            }
        }

        if ($orgId === null) {
            return response()->json([
                'error'   => 'organization_required',
                'message' => 'Provide an X-Organization header (slug or ID) to specify the active organization.',
            ], 400);
        }

        // Verify the user has access to the requested org
        if (! in_array($orgId, $user->accessibleOrganizationIds(), true)) {
            // A user reaching for an org they do not belong to is the exact
            // cross-tenant probe worth alerting on — recorded to both the
            // security log file and the auditable trail, never silent.
            \Illuminate\Support\Facades\Log::channel('security')->warning('Cross-tenant access denied', [
                'user_id'        => $user->id,
                'requested_org'  => $orgId,
                'ip'             => $request->ip(),
                'path'           => $request->path(),
            ]);
            \App\Services\Audit\AuditLogger::record(
                action: 'access_denied',
                description: "Attempted access to organisation #{$orgId} without membership",
                organizationId: (int) $orgId,
                userId: $user->id, actorName: $user->name,
            );

            return response()->json([
                'error'   => 'organization_access_denied',
                'message' => 'You do not have access to the requested organization.',
            ], 403);
        }

        $org = Organization::find($orgId);

        if (! $org || ! $org->is_active) {
            return response()->json([
                'error'   => 'organization_not_found',
                'message' => 'The requested organization does not exist or is inactive.',
            ], 404);
        }

        app()->instance('current.organization_id', $orgId);
        app()->instance('current.organization', $org);

        return $next($request);
    }

    /**
     * Resolve organization ID from the X-Organization header.
     * Accepts either a numeric ID or a slug string.
     */
    private function resolveFromHeader(Request $request): ?int
    {
        $header = $request->header('X-Organization');

        if (! $header) {
            return null;
        }

        if (is_numeric($header)) {
            return (int) $header;
        }

        // Resolve slug to ID
        $org = Organization::where('slug', $header)->first();

        return $org?->id;
    }
}
