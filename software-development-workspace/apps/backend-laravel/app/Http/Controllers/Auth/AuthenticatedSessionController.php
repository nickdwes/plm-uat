<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use App\Services\Audit\AuditLogger;
use App\Services\Identity\Authenticator;
use App\Models\User;

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

        $user = $request->user();

        /*
         * The second factor, for the people who have one.
         *
         * Deliberately conditional on an enrolment that exists and has been
         * confirmed — not on the method alone. Requiring a code from somebody
         * who has never scanned a QR locks them out of their own account, and
         * on this tenant that would have been everybody.
         *
         * No token is issued here. The challenge is a random id held in the
         * cache for five minutes and spent on first use; it authenticates
         * nothing on its own, so a password alone still buys an attacker
         * exactly nothing.
         */
        if ($user->auth_method === User::AUTH_LOCAL_TOTP && $user->hasConfirmedAuthenticator()) {
            $challenge = bin2hex(random_bytes(32));

            Cache::put("mfa:challenge:{$challenge}", $user->id, now()->addMinutes(5));

            // The session/guard state from the password step is dropped: until
            // the code arrives, this request has not authenticated anybody.
            Auth::guard('web')->logout();

            return response()->json([
                'mfa_required' => true,
                'method'       => $user->otp_preference ?? User::OTP_AUTHENTICATOR,
                'challenge'    => $challenge,
                'message'      => 'Enter the six-digit code from your authenticator app.',
            ], 200);
        }

        return $this->issueSession($user);
    }

    /**
     * Second step for anybody with an authenticator: the code.
     *
     * The challenge is spent the moment it is looked up, whether or not the
     * code turns out to be right — otherwise one password gives an attacker
     * five minutes of unlimited guesses against a six-digit number.
     * A wrong code means starting again from the password.
     */
    public function verifyOtp(Request $request, Authenticator $authenticator): JsonResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string', 'size:64'],
            'code'      => ['required', 'string', 'max:24'],
        ]);

        $key    = "mfa:challenge:{$data['challenge']}";
        $userId = Cache::pull($key);

        if (! $userId) {
            throw ValidationException::withMessages([
                'challenge' => 'That sign-in attempt has expired. Start again.',
            ]);
        }

        $user = User::find($userId);

        if (! $user) {
            throw ValidationException::withMessages(['challenge' => 'That sign-in attempt is no longer valid.']);
        }

        try {
            // A recovery code is longer than six digits, so the shape of what
            // was typed decides which is being offered — no extra field for
            // somebody already locked out of their phone to get wrong.
            $ok = preg_match('/^\d{6}$/', preg_replace('/\s+/', '', $data['code']) ?? '')
                ? $authenticator->verify($user, $data['code'])
                : $authenticator->useRecoveryCode($user, $data['code']);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['code' => $e->getMessage()]);
        }

        if (! $ok) {
            throw ValidationException::withMessages([
                'code' => 'That code did not match. Start again from your password.',
            ]);
        }

        AuditLogger::record(
            action: 'identity.mfa_passed',
            auditable: $user,
            description: "{$user->email} completed the second factor",
            userId: $user->id,
        );

        return $this->issueSession($user);
    }

    /** The part that is the same however somebody proved who they are. */
    private function issueSession(User $user): JsonResponse
    {
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

        /*
         * The caller's rights in the organisation the client will land in
         * (it picks the first of the list), so the first paint needs no
         * second round trip. Switching org refetches from /my-permissions.
         */
        $organizations = \App\Models\Organization::whereIn('id', $orgIds)
            ->get(['id', 'name', 'slug', 'logo_path', 'primary_color']);

        return response()->json([
            'token' => $token,
            'user'  => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
            ],
            'permissions' => $user->effectivePermissionsFor($organizations->first()?->id),
            'organizations' => $organizations
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

