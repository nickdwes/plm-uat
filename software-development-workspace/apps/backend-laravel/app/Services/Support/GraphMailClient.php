<?php

namespace App\Services\Support;

use App\Models\Integration\IntegrationConnection;
use App\Models\Support\Mailbox;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The Microsoft Graph calls the desk needs.
 *
 * Deliberately thin, and deliberately the only place that talks to Graph, so
 * the ingestion rules stay testable without a network. Credentials come from
 * the organisation's existing integration connection rather than a second copy
 * in configuration — two places to rotate a secret means one that does not get
 * rotated.
 */
class GraphMailClient
{
    private const BASE = 'https://graph.microsoft.com/v1.0';

    /** One message, with the headers threading depends on. */
    public function message(Mailbox $mailbox, string $messageId): array
    {
        $response = $this->request($mailbox)
            ->get(self::BASE . "/users/{$mailbox->address}/messages/" . rawurlencode($messageId), [
                // internetMessageHeaders is not returned by default and is the
                // whole basis of correct threading.
                '$select' => 'id,internetMessageId,subject,bodyPreview,body,from,toRecipients,'
                    . 'ccRecipients,receivedDateTime,hasAttachments,internetMessageHeaders,conversationId',
            ]);

        if ($response->failed()) {
            throw new RuntimeException("Graph message fetch failed: " . $response->body());
        }

        return $this->normalise($response->json());
    }

    /** Recent messages, for the poll that backs up the webhook. */
    public function recentMessages(Mailbox $mailbox, int $limit = 25): array
    {
        $response = $this->request($mailbox)
            ->get(self::BASE . "/users/{$mailbox->address}/mailFolders/inbox/messages", [
                '$top'     => $limit,
                '$orderby' => 'receivedDateTime desc',
                '$select'  => 'id,internetMessageId,subject,bodyPreview,body,from,toRecipients,'
                    . 'ccRecipients,receivedDateTime,hasAttachments,internetMessageHeaders,conversationId',
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Graph message list failed: ' . $response->body());
        }

        return array_map(fn ($m) => $this->normalise($m), $response->json('value') ?? []);
    }

    public function attachments(Mailbox $mailbox, string $messageId): array
    {
        $response = $this->request($mailbox)
            ->get(self::BASE . "/users/{$mailbox->address}/messages/" . rawurlencode($messageId) . '/attachments');

        return $response->successful() ? ($response->json('value') ?? []) : [];
    }

    /**
     * Subscribe to new mail.
     *
     * Graph caps mailbox subscriptions at about three days, so this is renewed
     * rather than set once — and the poll exists because a subscription that
     * silently lapses is otherwise a support desk that goes quiet.
     */
    public function subscribe(Mailbox $mailbox, string $notificationUrl, string $clientState): array
    {
        $response = $this->request($mailbox)->post(self::BASE . '/subscriptions', [
            'changeType'         => 'created',
            'notificationUrl'    => $notificationUrl,
            'resource'           => "users/{$mailbox->address}/mailFolders('inbox')/messages",
            'expirationDateTime' => now()->addDays(2)->addHours(12)->toIso8601ZuluString(),
            'clientState'        => $clientState,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Graph subscribe failed: ' . $response->body());
        }

        return $response->json();
    }

    public function renew(Mailbox $mailbox): array
    {
        $response = $this->request($mailbox)
            ->patch(self::BASE . '/subscriptions/' . $mailbox->subscription_id, [
                'expirationDateTime' => now()->addDays(2)->addHours(12)->toIso8601ZuluString(),
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Graph renew failed: ' . $response->body());
        }

        return $response->json();
    }

    public function unsubscribe(Mailbox $mailbox): void
    {
        if ($mailbox->subscription_id) {
            $this->request($mailbox)->delete(self::BASE . '/subscriptions/' . $mailbox->subscription_id);
        }
    }

    /**
     * Reply on the thread, so the customer's client keeps it together.
     *
     * Uses Graph's reply action rather than composing a new message: it sets
     * In-Reply-To and References for us, which is what makes the customer's
     * next reply land back on the right ticket.
     */
    public function reply(Mailbox $mailbox, string $messageId, string $html): void
    {
        $response = $this->request($mailbox)
            ->post(self::BASE . "/users/{$mailbox->address}/messages/" . rawurlencode($messageId) . '/reply', [
                'message' => ['body' => ['contentType' => 'HTML', 'content' => $html]],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Graph reply failed: ' . $response->body());
        }
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /** Graph's shape flattened to what the processor expects. */
    private function normalise(array $m): array
    {
        $headers = collect($m['internetMessageHeaders'] ?? [])
            ->mapWithKeys(fn ($h) => [mb_strtolower($h['name'] ?? '') => $h['value'] ?? null]);

        $addresses = fn (?array $list) => collect($list ?? [])
            ->map(fn ($r) => [
                'name'    => $r['emailAddress']['name'] ?? null,
                'address' => $r['emailAddress']['address'] ?? null,
            ])->all();

        return [
            'id'                  => $m['id'] ?? null,
            'internet_message_id' => $m['internetMessageId'] ?? null,
            'in_reply_to'         => $headers['in-reply-to'] ?? null,
            'references'          => $headers['references'] ?? null,
            'from_name'           => $m['from']['emailAddress']['name'] ?? null,
            'from_email'          => $m['from']['emailAddress']['address'] ?? null,
            'subject'             => $m['subject'] ?? null,
            'body_preview'        => $m['bodyPreview'] ?? null,
            'body_html'           => $m['body']['content'] ?? null,
            'received_at'         => $m['receivedDateTime'] ?? null,
            'to'                  => $addresses($m['toRecipients'] ?? null),
            'cc'                  => $addresses($m['ccRecipients'] ?? null),
            'has_attachments'     => (bool) ($m['hasAttachments'] ?? false),
            'attachments'         => [],
        ];
    }

    private function request(Mailbox $mailbox)
    {
        return Http::withToken($this->token($mailbox))->timeout(20)->acceptJson();
    }

    private function token(Mailbox $mailbox): string
    {
        $connection = $mailbox->connection;

        if (! $connection) {
            throw new RuntimeException(
                "Mailbox {$mailbox->address} has no integration connection, so nothing can read it."
            );
        }

        return $this->tokenFor($connection);
    }

    private function tokenFor(IntegrationConnection $connection): string
    {
        $key = 'support_graph_token_' . $connection->id;

        return Cache::remember($key, 3500, function () use ($connection): string {
            $response = Http::asForm()->timeout(15)->post(
                "https://login.microsoftonline.com/{$connection->tenant_id}/oauth2/v2.0/token",
                [
                    'grant_type'    => 'client_credentials',
                    'client_id'     => $connection->client_id,
                    'client_secret' => $connection->client_secret,
                    'scope'         => 'https://graph.microsoft.com/.default',
                ],
            );

            if ($response->failed()) {
                throw new RuntimeException(
                    'Graph token request failed: ' . ($response->json('error_description') ?? $response->body())
                );
            }

            return $response->json('access_token');
        });
    }
}
