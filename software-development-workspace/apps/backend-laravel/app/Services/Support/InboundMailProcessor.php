<?php

namespace App\Services\Support;

use App\Models\Support\Mailbox;
use App\Models\Support\Ticket;
use App\Models\Support\TicketEmail;
use App\Models\Support\TicketMessage;
use Illuminate\Support\Facades\DB;

/**
 * One inbound message, turned into a ticket or a reply on one.
 *
 * Takes a message already normalised into an array rather than talking to
 * Graph itself. Two reasons: the whole of this logic is testable without a
 * network, and a second source of mail — a poll instead of a webhook, or IMAP
 * one day — reaches the same rules rather than growing its own.
 *
 * ── Delivered more than once ──
 *
 * Graph does not promise exactly-once delivery, and a webhook that times out is
 * retried. The message identifier is unique per mailbox, so a redelivery finds
 * the row and stops. Without that, a slow afternoon produces duplicate tickets
 * and duplicate auto-replies to the same customer.
 */
class InboundMailProcessor
{
    public function __construct(
        private readonly TicketService $tickets,
        private readonly MailThreader $threader,
        private readonly SenderPolicy $policy,
    ) {}

    /**
     * @param  array $message keys: id, internet_message_id, in_reply_to, references,
     *                        from_email, from_name, subject, body_html, body_preview,
     *                        received_at, to[], cc[], attachments[]
     * @return array{status: string, ticket: ?Ticket, email: ?TicketEmail}
     */
    public function handle(Mailbox $mailbox, array $message): array
    {
        $existing = TicketEmail::withoutGlobalScope('organization')
            ->where('support_mailbox_id', $mailbox->id)
            ->where('graph_message_id', $message['id'] ?? null)
            ->first();

        if ($existing) {
            // Seen already. Not an error — this is the normal shape of a retry.
            return ['status' => 'duplicate', 'ticket' => $existing->ticket, 'email' => $existing];
        }

        $decision = $this->policy->decide($mailbox->organization_id, $message['from_email'] ?? null);

        return DB::transaction(function () use ($mailbox, $message, $decision) {
            /*
             * A blocked sender still leaves a record. "We never received it" and
             * "we refused it" are different answers, and only one of them can be
             * given without a row.
             */
            if ($decision['decision'] === SenderPolicy::BLOCK) {
                $email = $this->recordEmail($mailbox, $message, null, null, $decision['reason']);

                return ['status' => 'blocked', 'ticket' => null, 'email' => $email];
            }

            $ticket = $this->threader->match($mailbox->organization_id, $message);

            /*
             * Quarantine applies to new conversations only. Somebody already on
             * a ticket writing back is not a stranger, whatever the whitelist
             * says — and holding their reply would strand a live conversation.
             */
            if (! $ticket && $decision['decision'] === SenderPolicy::QUARANTINE) {
                $email = $this->recordEmail($mailbox, $message, null, null, $decision['reason']);

                return ['status' => 'quarantined', 'ticket' => null, 'email' => $email];
            }

            if ($ticket) {
                return $this->appendToTicket($mailbox, $ticket, $message);
            }

            return $this->openTicket($mailbox, $message);
        });
    }

    /** Release something held, and let it become a ticket after all. */
    public function release(TicketEmail $email): array
    {
        $mailbox = $email->mailbox;

        if (! $mailbox || ! $email->isQuarantined()) {
            return ['status' => 'not_quarantined', 'ticket' => $email->ticket, 'email' => $email];
        }

        $message = $this->messageFromRecord($email);

        return DB::transaction(function () use ($mailbox, $message, $email) {
            $ticket = $this->threader->match($mailbox->organization_id, $message)
                ?? $this->tickets->open($mailbox->organization_id, $this->ticketAttributes($mailbox, $message));

            $threadMessage = $this->appendMessage($ticket, $message, opening: false);

            $email->forceFill([
                'support_ticket_id'         => $ticket->id,
                'support_ticket_message_id' => $threadMessage->id,
                'released_at'               => now(),
            ])->save();

            return ['status' => 'released', 'ticket' => $ticket, 'email' => $email->fresh()];
        });
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function openTicket(Mailbox $mailbox, array $message): array
    {
        $ticket = $this->tickets->open(
            $mailbox->organization_id,
            $this->ticketAttributes($mailbox, $message),
        );

        /*
         * The ticket service already wrote the body as the opening message, so
         * this links the email to it rather than writing a second copy of the
         * same text into the thread.
         */
        $opening = $ticket->messages()->orderBy('id')->first();

        $email = $this->recordEmail($mailbox, $message, $ticket, $opening);

        return ['status' => 'opened', 'ticket' => $ticket, 'email' => $email];
    }

    private function appendToTicket(Mailbox $mailbox, Ticket $ticket, array $message): array
    {
        $threadMessage = $this->appendMessage($ticket, $message, opening: false);
        $email         = $this->recordEmail($mailbox, $message, $ticket, $threadMessage);

        return ['status' => 'appended', 'ticket' => $ticket, 'email' => $email];
    }

    private function appendMessage(Ticket $ticket, array $message, bool $opening): TicketMessage
    {
        return $this->tickets->reply($ticket, [
            'body'          => $message['body_html'] ?? $message['body_preview'] ?? '',
            'visibility'    => TicketMessage::VISIBILITY_PUBLIC,
            'author_name'   => $message['from_name'] ?? null,
            'author_email'  => $message['from_email'] ?? null,
            'source'        => 'email',
            // Mail from a customer is never a first response, whoever sent it.
            'is_from_agent' => false,
        ]);
    }

    private function ticketAttributes(Mailbox $mailbox, array $message): array
    {
        return [
            'subject'         => $this->cleanSubject($message['subject'] ?? '(no subject)'),
            'body'            => $message['body_html'] ?? $message['body_preview'] ?? '',
            'requester_name'  => $message['from_name'] ?? null,
            'requester_email' => $message['from_email'] ?? null,
            'ticket_type_id'  => $mailbox->default_type_id,
            'source'          => 'email',
        ];
    }

    /**
     * Strip the reply prefixes and our own reference from the subject.
     *
     * Cosmetic, and worth doing: a ticket titled "RE: FW: RE: [SD-2026-00001]
     * Internet down" is the same ticket as "Internet down" and reads worse in
     * every list it appears in.
     */
    private function cleanSubject(string $subject): string
    {
        $subject = preg_replace('/\[(SD-\d{4}-\d{5})\]/i', '', $subject) ?? $subject;
        $subject = preg_replace('/^(\s*(re|fw|fwd)\s*:\s*)+/i', '', $subject) ?? $subject;

        return trim($subject) ?: '(no subject)';
    }

    private function recordEmail(
        Mailbox $mailbox,
        array $message,
        ?Ticket $ticket,
        ?TicketMessage $threadMessage,
        ?string $quarantineReason = null,
    ): TicketEmail {
        $email = TicketEmail::create([
            'organization_id'           => $mailbox->organization_id,
            'support_mailbox_id'        => $mailbox->id,
            'support_ticket_id'         => $ticket?->id,
            'support_ticket_message_id' => $threadMessage?->id,
            'direction'                 => TicketEmail::INBOUND,
            'graph_message_id'          => $message['id'] ?? null,
            'internet_message_id'       => $message['internet_message_id'] ?? null,
            'in_reply_to'               => $message['in_reply_to'] ?? null,
            'references'                => $message['references'] ?? null,
            'from_name'                 => $message['from_name'] ?? null,
            'from_email'                => $message['from_email'] ?? null,
            'subject'                   => $message['subject'] ?? null,
            'body_preview'              => $message['body_preview'] ?? null,
            'body_html'                 => $message['body_html'] ?? null,
            'received_at'               => $message['received_at'] ?? now(),
            'quarantine_reason'         => $quarantineReason,
        ]);

        foreach (['to', 'cc', 'bcc'] as $kind) {
            foreach ($message[$kind] ?? [] as $recipient) {
                if (blank($recipient['address'] ?? null)) {
                    continue;
                }

                $email->recipients()->create([
                    'kind'    => $kind,
                    'name'    => $recipient['name'] ?? null,
                    'address' => $recipient['address'],
                ]);
            }
        }

        /*
         * Metadata now, bytes later. Knowing an attachment existed and having
         * failed to download it is a recoverable state; not knowing is not.
         */
        foreach ($message['attachments'] ?? [] as $attachment) {
            $email->attachments()->create([
                'graph_attachment_id' => $attachment['id'] ?? null,
                'name'                => $attachment['name'] ?? 'attachment',
                'mime_type'           => $attachment['contentType'] ?? null,
                'size_bytes'          => (int) ($attachment['size'] ?? 0),
            ]);
        }

        return $email;
    }

    /** Rebuild the normalised shape from a stored record, for release. */
    private function messageFromRecord(TicketEmail $email): array
    {
        return [
            'id'                  => $email->graph_message_id,
            'internet_message_id' => $email->internet_message_id,
            'in_reply_to'         => $email->in_reply_to,
            'references'          => $email->references,
            'from_name'           => $email->from_name,
            'from_email'          => $email->from_email,
            'subject'             => $email->subject,
            'body_html'           => $email->body_html,
            'body_preview'        => $email->body_preview,
            'received_at'         => $email->received_at,
        ];
    }
}
