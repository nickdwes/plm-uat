<?php

namespace Tests\Feature\Support;

use App\Models\Account\Account;
use App\Models\Account\AccountContact;
use App\Models\Organization;
use App\Models\Support\Mailbox;
use App\Models\Support\SenderRule;
use App\Models\Support\Ticket;
use App\Models\Support\TicketEmail;
use App\Services\Support\InboundMailProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mail into tickets.
 *
 * The old module threaded on `message_id = ? or subject = ? or subject = ?`, so
 * two customers who both wrote "Internet down" landed on one ticket and read
 * each other's correspondence — and its sender whitelist was loaded and never
 * checked, so anybody could open a ticket and get a reply carrying the
 * company's name. Both faults have tests here that would fail if they came back.
 */
class MailIngestionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Mailbox $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);

        $this->mailbox = Mailbox::create([
            'organization_id' => $this->org->id,
            'address'         => 'support@kns.co.ke',
            'client_state'    => 'secret-state',
        ]);

        // A known customer, so the default policy lets normal mail through.
        $account = Account::create([
            'organization_id' => $this->org->id,
            'name' => 'Client Ltd', 'reference' => 'ACC-1', 'status' => 'active',
        ]);
        AccountContact::create([
            'organization_id' => $this->org->id,
            'account_id' => $account->id,
            'first_name' => 'Ann', 'last_name' => 'Otieno',
            'email' => 'ann@client.co.ke',
        ]);
    }

    private function processor(): InboundMailProcessor
    {
        return app(InboundMailProcessor::class);
    }

    private function message(array $overrides = []): array
    {
        return array_merge([
            'id'                  => 'graph-' . fake()->unique()->numberBetween(1, 999999),
            'internet_message_id' => '<' . fake()->unique()->uuid() . '@client.co.ke>',
            'in_reply_to'         => null,
            'references'          => null,
            'from_name'           => 'Ann Otieno',
            'from_email'          => 'ann@client.co.ke',
            'subject'             => 'Internet down',
            'body_html'           => '<p>Nothing since this morning.</p>',
            'body_preview'        => 'Nothing since this morning.',
            'received_at'         => now(),
            'to'                  => [['name' => 'Support', 'address' => 'support@kns.co.ke']],
            'cc'                  => [],
            'attachments'         => [],
        ], $overrides);
    }

    private function ingest(array $overrides = []): array
    {
        return $this->processor()->handle($this->mailbox, $this->message($overrides));
    }

    // ── Opening ───────────────────────────────────────────────────────────────

    public function test_mail_from_a_known_contact_opens_a_ticket(): void
    {
        $result = $this->ingest();

        $this->assertSame('opened', $result['status']);
        $this->assertSame('Internet down', $result['ticket']->subject);
        $this->assertSame('email', $result['ticket']->source);
        $this->assertSame('ann@client.co.ke', $result['ticket']->requester_email);
    }

    /** Every recipient is kept, because "who else was copied?" gets asked. */
    public function test_recipients_and_attachments_are_recorded(): void
    {
        $result = $this->ingest([
            'cc' => [['name' => 'Ops', 'address' => 'ops@client.co.ke']],
            'attachments' => [['id' => 'a1', 'name' => 'trace.txt', 'contentType' => 'text/plain', 'size' => 900]],
        ]);

        $email = $result['email'];

        $this->assertSame(1, $email->recipients()->where('kind', 'to')->count());
        $this->assertSame(1, $email->recipients()->where('kind', 'cc')->count());
        $this->assertSame('trace.txt', $email->attachments()->first()->name);
        // Metadata now, bytes later — not yet downloaded is a legitimate state.
        $this->assertFalse($email->attachments()->first()->isDownloaded());
    }

    public function test_reply_prefixes_and_our_reference_are_stripped_from_the_subject(): void
    {
        $result = $this->ingest(['subject' => 'RE: FW: [SD-2026-00009] Internet down']);

        $this->assertSame('Internet down', $result['ticket']->subject);
    }

    // ── Delivered twice ───────────────────────────────────────────────────────

    /**
     * Graph does not promise exactly-once delivery and retries a slow webhook.
     * A redelivery must not become a second ticket and a second auto-reply.
     */
    public function test_the_same_message_delivered_twice_makes_one_ticket(): void
    {
        $message = $this->message();

        $first  = $this->processor()->handle($this->mailbox, $message);
        $second = $this->processor()->handle($this->mailbox, $message);

        $this->assertSame('opened', $first['status']);
        $this->assertSame('duplicate', $second['status']);
        $this->assertSame(1, Ticket::count());
    }

    // ── Threading ─────────────────────────────────────────────────────────────

    public function test_a_reply_threads_by_in_reply_to(): void
    {
        $opened = $this->ingest();
        $first  = $opened['email'];

        $result = $this->ingest([
            'subject'     => 'Re: Internet down',
            'in_reply_to' => $first->internet_message_id,
        ]);

        $this->assertSame('appended', $result['status']);
        $this->assertSame($opened['ticket']->id, $result['ticket']->id);
        $this->assertSame(1, Ticket::count());
    }

    public function test_a_reply_threads_by_the_references_chain(): void
    {
        $opened = $this->ingest();

        $result = $this->ingest([
            'references' => '<other@x.test> ' . $opened['email']->internet_message_id,
        ]);

        $this->assertSame($opened['ticket']->id, $result['ticket']->id);
    }

    /**
     * The old module's bug, as a test. Two customers, same subject, no shared
     * headers — two tickets, and neither can read the other.
     */
    public function test_two_customers_with_the_same_subject_do_not_share_a_ticket(): void
    {
        SenderRule::create([
            'organization_id' => $this->org->id,
            'kind' => 'domain', 'value' => 'other.co.ke', 'action' => 'allow',
        ]);

        $a = $this->ingest(['subject' => 'Internet down']);
        $b = $this->ingest([
            'subject'    => 'Internet down',
            'from_email' => 'james@other.co.ke',
            'from_name'  => 'James',
        ]);

        $this->assertNotSame($a['ticket']->id, $b['ticket']->id);
        $this->assertSame(2, Ticket::count());
    }

    /** The reference is honoured when the sender is already on the ticket. */
    public function test_the_subject_reference_threads_for_somebody_on_the_ticket(): void
    {
        $opened = $this->ingest();
        $ref    = $opened['ticket']->reference;

        $result = $this->ingest([
            'subject'     => "Re: [{$ref}] Internet down",
            'in_reply_to' => null,
        ]);

        $this->assertSame($opened['ticket']->id, $result['ticket']->id);
    }

    /**
     * And ignored for a stranger. A reference is visible to anyone who has ever
     * been forwarded one of our emails; without this guard, quoting it would
     * read somebody else's correspondence.
     */
    public function test_a_stranger_quoting_a_reference_does_not_reach_the_ticket(): void
    {
        SenderRule::create([
            'organization_id' => $this->org->id,
            'kind' => 'domain', 'value' => 'elsewhere.test', 'action' => 'allow',
        ]);

        $opened = $this->ingest();
        $ref    = $opened['ticket']->reference;

        $result = $this->ingest([
            'subject'    => "Re: [{$ref}] Internet down",
            'from_email' => 'stranger@elsewhere.test',
            'from_name'  => 'Stranger',
        ]);

        $this->assertNotSame($opened['ticket']->id, $result['ticket']->id);
        $this->assertSame('opened', $result['status']);
    }

    /** A colleague who was copied in replies from their own address. */
    public function test_somebody_copied_on_the_thread_may_reply_into_it(): void
    {
        $opened = $this->ingest([
            'cc' => [['name' => 'Ops', 'address' => 'ops@client.co.ke']],
        ]);
        $ref = $opened['ticket']->reference;

        $result = $this->ingest([
            'subject'    => "Re: [{$ref}] Internet down",
            'from_email' => 'ops@client.co.ke',
            'from_name'  => 'Ops',
        ]);

        $this->assertSame($opened['ticket']->id, $result['ticket']->id);
    }

    // ── Sender policy ─────────────────────────────────────────────────────────

    /**
     * The other old bug. A stranger's mail is held — not turned into a ticket,
     * and not auto-replied to, which is what made the old desk a spam
     * amplifier with the company's name on it.
     */
    public function test_an_unknown_sender_is_quarantined_rather_than_opening_a_ticket(): void
    {
        $result = $this->ingest(['from_email' => 'nobody@spam.test', 'from_name' => 'Nobody']);

        $this->assertSame('quarantined', $result['status']);
        $this->assertNull($result['ticket']);
        $this->assertSame(0, Ticket::count());
        $this->assertStringContainsString('not a known contact', $result['email']->quarantine_reason);
    }

    /** Held, not discarded — the message can still be produced. */
    public function test_quarantined_mail_is_kept_in_full(): void
    {
        $result = $this->ingest(['from_email' => 'nobody@spam.test']);

        $this->assertSame(1, TicketEmail::count());
        $this->assertStringContainsString('Nothing since this morning', $result['email']->body_html);
    }

    public function test_a_blocked_sender_leaves_a_record_but_no_ticket(): void
    {
        SenderRule::create([
            'organization_id' => $this->org->id,
            'kind' => 'address', 'value' => 'bad@spam.test', 'action' => 'block',
        ]);

        $result = $this->ingest(['from_email' => 'bad@spam.test']);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame(0, Ticket::count());
        // "We never received it" and "we refused it" are different answers.
        $this->assertSame(1, TicketEmail::count());
    }

    /** Blocking an address beats allowing its domain. */
    public function test_a_block_wins_over_an_allowed_domain(): void
    {
        SenderRule::create([
            'organization_id' => $this->org->id,
            'kind' => 'domain', 'value' => 'client.co.ke', 'action' => 'allow',
        ]);
        SenderRule::create([
            'organization_id' => $this->org->id,
            'kind' => 'address', 'value' => 'ann@client.co.ke', 'action' => 'block',
        ]);

        $this->assertSame('blocked', $this->ingest()['status']);
    }

    /**
     * Somebody already on a ticket is not a stranger, whatever the whitelist
     * says — holding their reply would strand a live conversation.
     */
    public function test_a_reply_on_an_existing_ticket_is_never_quarantined(): void
    {
        $opened = $this->ingest();

        // The contact is removed, so the policy would now hold their mail.
        AccountContact::query()->delete();

        $result = $this->ingest([
            'in_reply_to' => $opened['email']->internet_message_id,
            'subject'     => 'Re: Internet down',
        ]);

        $this->assertSame('appended', $result['status']);
    }

    // ── Release ───────────────────────────────────────────────────────────────

    public function test_releasing_held_mail_turns_it_into_a_ticket(): void
    {
        $held = $this->ingest(['from_email' => 'newclient@somewhere.test', 'from_name' => 'New']);
        $this->assertSame('quarantined', $held['status']);

        $released = $this->processor()->release($held['email']);

        $this->assertSame('released', $released['status']);
        $this->assertSame(1, Ticket::count());
        $this->assertNotNull($released['email']->released_at);
        $this->assertFalse($released['email']->fresh()->isQuarantined());
    }

    // ── The webhook ───────────────────────────────────────────────────────────

    /** Graph proves the endpoint is willing before any subscription exists. */
    public function test_the_webhook_echoes_the_validation_token(): void
    {
        $this->post('/api/v1/support/mail/webhook?validationToken=abc123')
            ->assertOk()
            ->assertSee('abc123');
    }

    /**
     * The endpoint is public by construction, so the shared secret is the only
     * thing between it and anybody who learns the URL.
     */
    public function test_a_notification_with_the_wrong_client_state_is_ignored(): void
    {
        $this->mailbox->update(['subscription_id' => 'sub-1']);

        $this->postJson('/api/v1/support/mail/webhook', [
            'value' => [[
                'subscriptionId' => 'sub-1',
                'clientState'    => 'not-the-secret',
                'resourceData'   => ['id' => 'graph-1'],
            ]],
        ])->assertStatus(202)->assertJsonPath('queued', 0);
    }

    public function test_a_notification_with_the_right_client_state_is_queued(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $this->mailbox->update(['subscription_id' => 'sub-1']);

        $this->postJson('/api/v1/support/mail/webhook', [
            'value' => [[
                'subscriptionId' => 'sub-1',
                'clientState'    => 'secret-state',
                'resourceData'   => ['id' => 'graph-1'],
            ]],
        ])->assertStatus(202)->assertJsonPath('queued', 1);

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\Support\IngestGraphMessage::class);
    }

    /** An unknown subscription is not an error worth failing Graph over. */
    public function test_an_unknown_subscription_is_accepted_and_ignored(): void
    {
        $this->postJson('/api/v1/support/mail/webhook', [
            'value' => [[
                'subscriptionId' => 'who-knows',
                'clientState'    => 'secret-state',
                'resourceData'   => ['id' => 'graph-1'],
            ]],
        ])->assertStatus(202)->assertJsonPath('queued', 0);
    }
}
