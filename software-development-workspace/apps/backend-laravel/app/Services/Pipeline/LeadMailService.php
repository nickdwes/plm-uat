<?php

namespace App\Services\Pipeline;

use App\Models\Pipeline\Lead;
use App\Models\Pipeline\LeadEmail;
use App\Models\Support\Mailbox;
use App\Services\Integration\CalendarConnectionResolver;
use App\Services\Support\GraphMailClient;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Pulling a lead's correspondence out of the organisation's mailbox.
 *
 * The project's version of this reads a mailbox belonging to the project. A
 * lead cannot work that way — nobody is going to stand up a mailbox for each of
 * 160 leads, and most are answered and closed inside a fortnight. What a lead
 * does have is people: the customer's contacts and its named stakeholders. So
 * the mail is found by who is on it.
 *
 * That makes the attachment a claim worth showing your working for, which is
 * why every stored message records the address that matched it. "This is here
 * because it involves j.biwott@kengen.co.ke" is checkable; "this is here"
 * is not.
 */
class LeadMailService
{
    public function __construct(private readonly GraphMailClient $graph)
    {
    }

    /**
     * Every address that would make a message this lead's business.
     *
     * Stakeholders first: somebody deliberately named them against this lead,
     * which is a stronger signal than being on the customer's contact list.
     * The account itself holds no address — only its contacts do.
     *
     * @return list<string>
     */
    public function correspondents(Lead $lead): array
    {
        $lead->loadMissing(['stakeholders', 'account.contacts']);

        return collect($lead->stakeholders)->pluck('email')
            ->merge(collect($lead->account?->contacts ?? [])->pluck('email'))
            ->filter(fn ($address) => filter_var($address, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array{status: string, fetched: int, new: int, addresses: int}
     */
    public function sync(Lead $lead, int $limit = 25): array
    {
        $addresses = $this->correspondents($lead);

        if ($addresses === []) {
            // Not an error. A lead with nobody named on it has no correspondence
            // to find, and saying so is more use than an empty list.
            return ['status' => 'no_correspondents', 'fetched' => 0, 'new' => 0, 'addresses' => 0];
        }

        $mailbox = $this->mailbox($lead);

        $messages = $this->graph->messagesWith($mailbox, $addresses, $limit);

        $new = 0;

        foreach ($messages as $m) {
            if (empty($m['id'])) {
                continue;
            }

            $email = LeadEmail::updateOrCreate(
                ['lead_id' => $lead->id, 'graph_id' => $m['id']],
                [
                    'organization_id'     => $lead->organization_id,
                    'internet_message_id' => $m['internet_message_id'] ?? null,
                    'matched_address'     => $m['matched_address'] ?? null,
                    'from_name'           => $m['from_name'] ?? null,
                    'from_email'          => $m['from_email'] ?? null,
                    'subject'             => $m['subject'] ?? null,
                    'body_preview'        => $m['body_preview'] ?? null,
                    'body_html'           => $m['body_html'] ?? null,
                    'to_recipients'       => $m['to'] ?? [],
                    'cc_recipients'       => $m['cc'] ?? [],
                    'has_attachments'     => $m['has_attachments'] ?? false,
                    'received_at'         => ! empty($m['received_at']) ? Carbon::parse($m['received_at']) : null,
                ],
            );

            if ($email->wasRecentlyCreated) {
                $new++;
            }
        }

        return [
            'status'    => 'ok',
            'fetched'   => count($messages),
            'new'       => $new,
            'addresses' => count($addresses),
        ];
    }

    /**
     * The box to read.
     *
     * Transient, like the project's — the organisation's own address on its
     * Graph connection, not a `support_mailboxes` row.
     */
    private function mailbox(Lead $lead): Mailbox
    {
        $address = env('HR_CALENDAR_MAILBOX', env('MAIL_FROM_ADDRESS', ''));

        if (! $address) {
            throw new RuntimeException(
                'No organisation mailbox is configured (HR_CALENDAR_MAILBOX), so there is nothing to read a lead\'s mail from.',
            );
        }

        $connection = CalendarConnectionResolver::resolve($lead->organization_id);

        $mailbox = new Mailbox([
            'organization_id'           => $lead->organization_id,
            'integration_connection_id' => $connection->id,
            'address'                   => $address,
        ]);
        $mailbox->setRelation('connection', $connection);

        return $mailbox;
    }
}
