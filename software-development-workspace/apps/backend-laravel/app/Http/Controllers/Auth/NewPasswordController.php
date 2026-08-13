<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;

class NewPasswordController extends Controller
{
    /**
     * Set the new password against a token from the emailed link.
     *
     * @throws ValidationException
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'token'    => ['required'],
            'email'    => ['required', 'email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user) use ($request) {
                /*
                 * A valid token is not on its own permission to get back in.
                 *
                 * Status can change between the link being sent and the link
                 * being used — somebody suspended on Friday still holds a
                 * working token until Saturday — and the check costs nothing.
                 */
                abort_unless(
                    $user->isAccountActive() && ! $user->sso_only,
                    403,
                    'That account cannot sign in with a password.',
                );

                $user->forceFill([
                    'password'       => Hash::make($request->string('password')),
                    'remember_token' => Str::random(60),
                ])->save();

                /*
                 * Every existing session dies with the old password.
                 *
                 * Most people reset because they forgot. The ones who matter
                 * reset because somebody else has been in the account, and
                 * leaving the intruder's bearer token valid means the reset
                 * changed nothing for them — the tokens here last a fortnight.
                 */
                $user->tokens()->delete();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            /*
             * Expired, already used, or forged. Named against `email` because
             * that is where the form shows it, and kept vague on purpose: which
             * of the three it was is not the resetter's business.
             */
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return response()->json([
            'status' => 'Your password has been changed. Sign in with it. '
                . 'Anywhere you were already signed in has been signed out.',
        ]);
    }
}
