<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Microsoft Azure AD SSO via OAuth2 Authorization Code flow.
 *
 * Flow:
 *  1. GET  /api/auth/azure/redirect  → returns { url } for the browser to follow
 *  2. Azure redirects to GET /api/auth/azure/callback?code=&state=
 *  3. Backend exchanges code → gets Graph profile → finds/creates user
 *  4. Stores a 60-second login_code in cache, redirects to:
 *       {FRONTEND_URL}/#/sso/callback?code={login_code}
 *  5. GET  /api/auth/azure/exchange  (SPA POST call) → returns Sanctum token + user + orgs
 *
 * Required Azure app registration:
 *  - Redirect URI: {APP_URL}/api/auth/azure/callback  (Web platform)
 *  - API permissions (Delegated): openid, profile, email, User.Read
 */
class AzureSsoController extends Controller
{
    private const SCOPES = 'openid profile email User.Read offline_access';

    // ── Step 1: Build & return the Azure authorisation URL ────────────────

    public function redirect(): JsonResponse
    {
        $state = Str::random(40);

        // Store state for 10 min to validate on callback
        Cache::put("azure_oauth_state_{$state}", true, 600);

        $params = http_build_query([
            'client_id'     => config('services.azure.client_id'),
            'response_type' => 'code',
            'redirect_uri'  => config('services.azure.redirect'),
            'response_mode' => 'query',
            'scope'         => self::SCOPES,
            'state'         => $state,
            'prompt'        => 'select_account',
        ]);

        $tenantId = config('services.azure.tenant_id', 'common');
        $url = "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/authorize?{$params}";

        return response()->json(['url' => $url]);
    }

    // ── Step 2: Handle callback from Azure ────────────────────────────────

    public function callback(Request $request): RedirectResponse
    {
        $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:5173'), '/');
        $errorRedirect = "{$frontendUrl}/#/login?sso_error=";

        // Validate state (CSRF protection)
        $state = $request->query('state', '');
        if (!$state || !Cache::pull("azure_oauth_state_{$state}")) {
            return redirect("{$errorRedirect}" . urlencode('Invalid state. Please try again.'));
        }

        if ($request->has('error')) {
            $desc = $request->query('error_description', $request->query('error'));
            return redirect("{$errorRedirect}" . urlencode($desc));
        }

        $code = $request->query('code');
        if (!$code) {
            return redirect("{$errorRedirect}" . urlencode('No authorization code received.'));
        }

        // Exchange code for tokens
        try {
            $tokenData = $this->exchangeCode($code);
        } catch (\Throwable $e) {
            return redirect("{$errorRedirect}" . urlencode('Failed to authenticate with Microsoft: ' . $e->getMessage()));
        }

        // Fetch user profile from Microsoft Graph
        try {
            $profile = $this->fetchProfile($tokenData['access_token']);
        } catch (\Throwable $e) {
            return redirect("{$errorRedirect}" . urlencode('Failed to retrieve your Microsoft profile.'));
        }

        // Find or create user
        $user = $this->findOrCreateUser($profile);

        // Create a short-lived one-time login code (60 s) instead of putting token in URL
        $loginCode = Str::random(48);
        Cache::put("azure_login_code_{$loginCode}", $user->id, 60);

        return redirect("{$frontendUrl}/#/sso/callback?code={$loginCode}");
    }

    // ── Step 3: SPA exchanges the short-lived code for a Sanctum token ────

    public function exchange(Request $request): JsonResponse
    {
        $code = $request->input('code');
        if (!$code) {
            return response()->json(['message' => 'Missing code.'], 422);
        }

        $userId = Cache::pull("azure_login_code_{$code}");
        if (!$userId) {
            return response()->json(['message' => 'Code is invalid or has expired. Please sign in again.'], 401);
        }

        $user = User::find($userId);
        if (!$user) {
            return response()->json(['message' => 'User account not found.'], 404);
        }

        // Revoke previous tokens
        $user->tokens()->delete();

        $token  = $user->createToken('sso-' . now()->timestamp, ['*'])->plainTextToken;
        $orgIds = $user->accessibleOrganizationIds();

        return response()->json([
            'token' => $token,
            'user'  => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
            ],
            'organizations' => Organization::whereIn('id', $orgIds)
                ->get(['id', 'name', 'slug', 'logo_path', 'primary_color'])
                ->map(fn($org) => [
                    'id'            => $org->id,
                    'name'          => $org->name,
                    'slug'          => $org->slug,
                    'logo_url'      => $org->logo_path
                        ? Storage::url($org->logo_path)
                        : null,
                    'primary_color' => $org->primary_color,
                ])
                ->values()
                ->toArray(),
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function exchangeCode(string $code): array
    {
        $tenantId = config('services.azure.tenant_id', 'common');

        $response = Http::asForm()
            ->timeout(15)
            ->post("https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token", [
                'grant_type'    => 'authorization_code',
                'client_id'     => config('services.azure.client_id'),
                'client_secret' => config('services.azure.client_secret'),
                'redirect_uri'  => config('services.azure.redirect'),
                'code'          => $code,
                'scope'         => self::SCOPES,
            ]);

        if ($response->failed()) {
            $err = $response->json('error_description') ?? $response->body();
            throw new \RuntimeException($err);
        }

        return $response->json();
    }

    private function fetchProfile(string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->timeout(10)
            ->get('https://graph.microsoft.com/v1.0/me', [
                '$select' => 'id,displayName,mail,userPrincipalName',
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Graph /me request failed: ' . $response->status());
        }

        return $response->json();
    }

    private function findOrCreateUser(array $profile): User
    {
        $azureId    = $profile['id'];
        $email      = $profile['mail'] ?? $profile['userPrincipalName'] ?? null;
        $name       = $profile['displayName'] ?? 'Azure User';

        // 1. Match on azure_id (most reliable)
        $user = User::where('azure_id', $azureId)->first();
        if ($user) {
            // Keep name/email in sync
            $user->update(array_filter(['name' => $name, 'email' => $email]));
            return $user;
        }

        // 2. Match on email — link the account
        if ($email) {
            $user = User::where('email', $email)->first();
            if ($user) {
                $user->update(['azure_id' => $azureId, 'name' => $name]);
                return $user;
            }
        }

        // 3. Create new user (SSO-only, no password)
        return User::create([
            'name'      => $name,
            'email'     => $email ?? "{$azureId}@azure.sso",
            'azure_id'  => $azureId,
            'sso_only'  => true,
            'password'  => \Illuminate\Support\Facades\Hash::make(Str::random(64)),
        ]);
    }
}
