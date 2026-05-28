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

        // Revoke all previous tokens so only one active token exists per user.
        $user->tokens()->delete();

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
                    'logo_url'      => $org->logo_path
                        ? \Illuminate\Support\Facades\Storage::url($org->logo_path)
                        : null,
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

