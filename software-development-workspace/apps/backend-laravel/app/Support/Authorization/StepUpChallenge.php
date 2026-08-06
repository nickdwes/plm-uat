<?php

namespace App\Support\Authorization;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * "Prove it is you, now" — the Entra claims challenge.
 *
 * Commercial figures are gated by a permission today. Entra Conditional Access
 * can additionally demand a fresh authenticator prompt before they are
 * released, through an Authentication Context; see
 * docs/entra-stepup-and-sharepoint.md.
 *
 * The step-up itself is not built. What is built is the wire format, because
 * that is the part which is expensive to retrofit: a client that treats every
 * 401 as "logged out" will sign people out instead of prompting them, and by
 * the time the step-up is switched on, that behaviour is spread across every
 * screen. Emitting the challenge shape now means the SPA learns to tell the
 * two apart while there is nothing at stake.
 *
 * The shape is Microsoft's, not ours:
 *
 *   401 Unauthorized
 *   WWW-Authenticate: Bearer error="insufficient_claims", claims="<base64url>"
 *
 * where the claims payload decodes to
 * {"access_token":{"acrs":{"essential":true,"value":"c1"}}}.
 */
final class StepUpChallenge
{
    /** The Entra authentication context id. Configured, not hard-coded. */
    public static function context(): string
    {
        return (string) config('services.azure.commercial_auth_context', 'c1');
    }

    /** Whether step-up is switched on. Off until the CA policy exists. */
    public static function enabled(): bool
    {
        return (bool) config('services.azure.require_step_up_for_commercials', false);
    }

    /**
     * The base64url claims parameter the SPA passes back to Entra.
     *
     * Unpadded base64url, as the protocol requires — padded base64 is rejected
     * by the authorize endpoint, which is a fiddly hour to discover later.
     */
    public static function claims(): string
    {
        $json = json_encode([
            'access_token' => [
                'acrs' => ['essential' => true, 'value' => self::context()],
            ],
        ], JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * Refuse with a challenge rather than a plain 403.
     *
     * 401, not 403: 403 means "you may not", which the client cannot act on.
     * 401 with insufficient_claims means "you may, once you re-authenticate",
     * which it can.
     */
    public static function refuse(): never
    {
        throw new HttpException(401, 'Re-authentication required to view commercial data.', null, [
            'WWW-Authenticate' => sprintf(
                'Bearer error="insufficient_claims", claims="%s"',
                self::claims(),
            ),
            // Repeated as a header the SPA can read without parsing
            // WWW-Authenticate, which CORS exposes awkwardly.
            'X-Step-Up-Required' => self::context(),
        ]);
    }
}
