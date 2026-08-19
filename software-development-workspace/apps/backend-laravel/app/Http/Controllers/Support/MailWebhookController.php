<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Jobs\Support\IngestGraphMessage;
use App\Models\Support\Mailbox;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where Microsoft Graph tells us there is new mail.
 *
 * Unauthenticated by necessity — Graph does not carry a bearer token — which is
 * exactly why every notification is checked against the mailbox's clientState
 * before anything is queued. Without that check this is an endpoint anybody who
 * learns the URL can drive, and driving it means making the desk fetch
 * arbitrary message identifiers.
 *
 * ── Answer quickly ──
 *
 * Graph expects a response within seconds and removes subscriptions whose
 * endpoint does not keep up. Nothing here does more than validate and queue.
 */
class MailWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        /*
         * The handshake. Graph proves the endpoint is willing by asking it to
         * echo a token back as text/plain — before any subscription exists, so
         * this necessarily precedes any clientState check.
         */
        if ($token = $request->query('validationToken')) {
            return response($token, 200, ['Content-Type' => 'text/plain']);
        }

        $queued = 0;

        foreach ($request->input('value', []) as $notification) {
            $subscriptionId = $notification['subscriptionId'] ?? null;
            $messageId      = $notification['resourceData']['id'] ?? null;

            if (! $subscriptionId || ! $messageId) {
                continue;
            }

            $mailbox = Mailbox::withoutGlobalScope('organization')
                ->where('subscription_id', $subscriptionId)
                ->where('is_active', true)
                ->first();

            if (! $mailbox) {
                continue;
            }

            /*
             * The shared secret, compared in constant time. A mismatch is
             * logged rather than answered differently: telling a caller which
             * part of their forgery was wrong is free help.
             */
            $presented = (string) ($notification['clientState'] ?? '');
            $expected  = (string) ($mailbox->client_state ?? '');

            if ($expected === '' || ! hash_equals($expected, $presented)) {
                Log::warning('support.mail.webhook.bad_client_state', [
                    'subscription' => $subscriptionId,
                    'mailbox'      => $mailbox->address,
                ]);

                continue;
            }

            IngestGraphMessage::dispatch($mailbox->id, $messageId);
            $queued++;
        }

        /*
         * 202 whatever happened. A notification this system rejects is not
         * Graph's problem to retry, and an error status here eventually costs
         * the subscription.
         */
        return response()->json(['queued' => $queued], 202);
    }
}
