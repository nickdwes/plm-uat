<?php

namespace App\Mail\Transports;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Laravel mail transport for Microsoft Graph API (client credentials flow).
 *
 * Required Azure app permissions (application, not delegated):
 *   - Mail.Send
 *
 * The sending mailbox must be a licensed Exchange Online mailbox.
 * The app must be granted Mail.Send for all mailboxes or restricted to
 * specific mailboxes via an Exchange Application Access Policy.
 */
class MicrosoftGraphTransport extends AbstractTransport
{
    public function __construct(
        private readonly string $tenantId,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $fromAddress,
        private readonly string $fromName = '',
    ) {
        parent::__construct();
    }

    // ── AbstractTransport contract ─────────────────────────────────────────

    protected function doSend(SentMessage $message): void
    {
        /** @var Email $email */
        $email   = $message->getOriginalMessage();
        $token   = $this->getAccessToken();
        $payload = $this->buildPayload($email);

        // Send as the from-address mailbox (must have Mail.Send permission)
        $sender   = $email->getFrom()[0] ?? null;
        $mailbox  = $sender ? $sender->getAddress() : $this->fromAddress;

        $response = Http::withToken($token)
            ->timeout(15)
            ->post("https://graph.microsoft.com/v1.0/users/{$mailbox}/sendMail", $payload);

        if ($response->failed()) {
            $body = $response->json() ?? $response->body();
            $err  = is_array($body) ? ($body['error']['message'] ?? json_encode($body)) : (string) $body;
            throw new \RuntimeException("Microsoft Graph sendMail failed [{$response->status()}]: {$err}");
        }
    }

    public function __toString(): string
    {
        return 'graph';
    }

    // ── Access token (cached for ~58 min) ─────────────────────────────────

    private function getAccessToken(): string
    {
        $key = 'ms_graph_mail_token_' . md5($this->clientId . $this->tenantId);

        return Cache::remember($key, 3500, function (): string {
            $response = Http::asForm()
                ->timeout(10)
                ->post("https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token", [
                    'grant_type'    => 'client_credentials',
                    'client_id'     => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'scope'         => 'https://graph.microsoft.com/.default',
                ]);

            if ($response->failed()) {
                $err = $response->json('error_description') ?? $response->body();
                throw new \RuntimeException("Microsoft Graph token request failed: {$err}");
            }

            return $response->json('access_token');
        });
    }

    // ── Payload builder ────────────────────────────────────────────────────

    private function buildPayload(Email $email): array
    {
        $from = $email->getFrom()[0] ?? null;

        $message = [
            'subject' => $email->getSubject() ?? '(no subject)',
            'body'    => [
                'contentType' => $email->getHtmlBody() ? 'HTML' : 'Text',
                'content'     => $email->getHtmlBody() ?? $email->getTextBody() ?? '',
            ],
            'toRecipients' => $this->mapAddresses($email->getTo()),
        ];

        if ($from) {
            $message['from'] = [
                'emailAddress' => [
                    'address' => $from->getAddress(),
                    'name'    => $from->getName() ?: $this->fromName,
                ],
            ];
        }

        if ($ccs = $email->getCc()) {
            $message['ccRecipients'] = $this->mapAddresses($ccs);
        }

        if ($bccs = $email->getBcc()) {
            $message['bccRecipients'] = $this->mapAddresses($bccs);
        }

        if ($replyTo = $email->getReplyTo()) {
            $message['replyTo'] = $this->mapAddresses($replyTo);
        }

        $attachments = $this->buildAttachments($email);
        if ($attachments) {
            $message['attachments'] = $attachments;
        }

        return [
            'message'         => $message,
            'saveToSentItems' => false,
        ];
    }

    /** @param Address[] $addresses */
    private function mapAddresses(array $addresses): array
    {
        return array_map(static fn(Address $addr) => [
            'emailAddress' => [
                'address' => $addr->getAddress(),
                'name'    => $addr->getName(),
            ],
        ], $addresses);
    }

    private function buildAttachments(Email $email): array
    {
        $result = [];

        foreach ($email->getAttachments() as $part) {
            /** @var DataPart $part */
            $filename = $part->getFilename() ?? 'attachment';

            $result[] = [
                '@odata.type'  => '#microsoft.graph.fileAttachment',
                'name'         => $filename,
                'contentType'  => $part->getMediaType() . '/' . $part->getMediaSubtype(),
                'contentBytes' => base64_encode($part->getBody()),
            ];
        }

        return $result;
    }
}
