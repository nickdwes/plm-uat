<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class AuthenticatedSessionController extends Controller
{
    /**
     * Handle an incoming authentication request.
     *
     * - SPA (Vue) clients receive a 200 JSON payload (session cookie already set).
     * - Mobile clients (X-Client: mobile header) additionally receive a Bearer token.
     */
    public function store(LoginRequest $request): JsonResponse
    {
        $request->authenticate();

        $user   = $request->user();
        $orgIds = $user->accessibleOrganizationIds();

        /*
         * Old tokens are pruned, not wiped.
         *
         * Deleting every token on login meant one session per person: opening
         * the app on a phone silently signed out the laptop, and the laptop
         * found out by painting "Unauthenticated." across whatever page it was
         * on. Somebody with two tabs did it to themselves.
         *
         * A fortnight unused is a session nobody is coming back to, and
         * dropping those keeps the table from growing without bound.
         */
        $user->tokens()
            ->where('created_at', '<', now()->subDays(14))
            ->where(fn ($q) => $q->whereNull('last_used_at')
                ->orWhere('last_used_at', '<', now()->subDays(14)))
            ->delete();

        $token = $user->createToken('spa-' . now()->timestamp, ['*'])->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
            ],
            'organizations' => \App\Models\Organization::whereIn('id', $orgIds)
                ->get(['id', 'name', 'slug', 'logo_path', 'primary_color'])
                ->map(fn ($org) => [
                    'id'            => $org->id,
                    'name'          => $org->name,
                    'slug'          => $org->slug,
                    'logo_url'      => $org->logoUrl(),
                    'primary_color' => $org->primary_color,
                ])
                ->toArray(),
        ]);
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): Response
    {
        // Revoke the current Sanctum Bearer token.
        $request->user()?->currentAccessToken()?->delete();

        return response()->noContent();
    }
}

