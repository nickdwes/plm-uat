<?php

namespace App\Jobs\Support;

use App\Models\Support\Mailbox;
use App\Services\Support\GraphMailClient;
use App\Services\Support\InboundMailProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Fetch one notified message and turn it into a ticket.
 *
 * Queued because Graph expects a webhook to answer within seconds and
 * unsubscribes endpoints that do not. Fetching the message, deciding on the
 * sender and writing the ticket is comfortably more work than that budget
 * allows, so the webhook records the notification and leaves.
 */
class IngestGraphMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Retries spaced out: a Graph blip clears in seconds, throttling does not. */
    public array $backoff = [10, 60, 300];

    public function __construct(
        public readonly int $mailboxId,
        public readonly string $messageId,
    ) {}

    public function handle(GraphMailClient $graph, InboundMailProcessor $processor): void
    {
        $mailbox = Mailbox::withoutGlobalScope('organization')->find($this->mailboxId);

        if (! $mailbox || ! $mailbox->is_active) {
            return;
        }

        // The organisation scope is resolved from a request, and there is no
        // request here — so it is set explicitly for everything downstream.
        app()->instance('current.organization_id', $mailbox->organization_id);

        $message = $graph->message($mailbox, $this->messageId);

        if ($message['has_attachments'] ?? false) {
            $message['attachments'] = $graph->attachments($mailbox, $this->messageId);
        }

        $result = $processor->handle($mailbox, $message);

        $mailbox->forceFill(['last_delivered_at' => now(), 'last_error' => null])->save();

        /*
         * The acknowledgement is sent after the ticket is committed and outside
         * its transaction, and its failure is swallowed. A customer who does not
         * get an auto-reply is mildly annoyed; a ticket rolled back because the
         * auto-reply failed is lost work nobody knows about.
         */
        if ($result['status'] === 'opened' && $mailbox->auto_acknowledge && $result['ticket']) {
            try {
                $graph->reply($mailbox, $this->messageId, $this->acknowledgement($result['ticket']));
            } catch (\Throwable $e) {
                Log::warning('support.mail.ack_failed', [
                    'ticket' => $result['ticket']->reference,
                    'error'  => $e->getMessage(),
                ]);
            }
        }

        Log::info('support.mail.ingested', [
            'mailbox' => $mailbox->address,
            'status'  => $result['status'],
            'ticket'  => $result['ticket']?->reference,
        ]);
    }

    /**
     * The reference goes in the subject and the body.
     *
     * It is what the customer quotes back, and — with the headers Graph sets on
     * a reply — what puts their next message on the right ticket rather than
     * opening a second one.
     */
    private function acknowledgement(\App\Models\Support\Ticket $ticket): string
    {
        $name = e($ticket->requester_name ?: 'there');
        $ref  = e($ticket->reference);

        return <<<HTML
        <p>Hello {$name},</p>
        <p>Thank you for getting in touch. We have logged this as <strong>[{$ref}]</strong>
           and someone will be in touch shortly.</p>
        <p style="color:#6b7280;font-size:13px">
           Please keep the reference in the subject line when you reply, so your message
           reaches the same ticket.</p>
        HTML;
    }

    public function failed(\Throwable $e): void
    {
        Mailbox::withoutGlobalScope('organization')
            ->where('id', $this->mailboxId)
            ->update(['last_error' => substr($e->getMessage(), 0, 480)]);
    }
}
