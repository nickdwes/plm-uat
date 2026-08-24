<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use App\Services\Security\StepUpVerification;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The gate a controller stands behind once a permission check alone is not
 * enough — proof that whoever holds this session is the one asking, not just
 * that the session is still valid.
 *
 * Answers with 401 and a body naming the purpose, deliberately distinct from
 * a plain 403. A blanket "401 means logged out" interceptor on the frontend
 * would otherwise sign somebody out instead of prompting them for a code —
 * the two have to stay distinguishable, or the UI cannot tell "you are not
 * signed in" from "you are signed in, but prove it again".
 */
trait RequiresStepUp
{
    protected function requireStepUp(User $user, string $purpose): void
    {
        /** @var StepUpVerification $service */
        $service = app(StepUpVerification::class);

        if ($service->isElevated($user, $purpose)) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'code'    => 'step_up_required',
            'purpose' => $purpose,
            'message' => 'This needs a fresh verification code before it can be shown.',
        ], 401));
    }
}
