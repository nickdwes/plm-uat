<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

class PasswordResetLinkController extends Controller
{
    /**
     * "I have forgotten my password."
     *
     * The reply is deliberately the same whatever happens: address on file or
     * not, account suspended or not, throttled or not. The scaffolded version
     * returned "We can't find a user with that email address", which turns this
     * endpoint into a way to ask whether somebody works here — and for a
     * company directory that is worth something to whoever is asking.
     *
     * The cost is that a typo looks like success. That is the right trade: the
     * person who typed it wrong finds out when no mail arrives, and the sentence
     * below tells them to check the address.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $email = $request->string('email')->toString();
        $user  = User::where('email', $email)->first();

        /*
         * Only active, password-holding accounts get a link.
         *
         * A leaver keeps their row so their history stays attributable, and
         * sending them a working reset link would hand a former employee the
         * way back in. Somebody who signs in through Azure has no local
         * password to reset — issuing one would quietly create a second way
         * into their account that bypasses the tenant's own sign-in policy.
         */
        if ($user && $user->isAccountActive() && ! $user->sso_only) {
            Password::sendResetLink(['email' => $email]);
        }

        return response()->json([
            'status' => 'If that address belongs to an account, a reset link is on its way. '
                . 'It expires in an hour.',
        ]);
    }
}
