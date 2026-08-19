<?php

namespace Tests\Feature\Support;

use App\Models\Account\Account;
use App\Models\Account\AccountContact;
use App\Models\Organization;
use App\Models\Support\PortalToken;
use App\Models\Support\Service;
use App\Models\Support\Survey;
use App\Models\Support\Ticket;
use App\Models\Support\TicketMessage;
use App\Models\Support\TicketStatus;
use App\Models\User;
use App\Services\Support\SupportDefaults;
use App\Services\Support\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The customer's half of the desk.
 *
 * Every endpoint here is reachable without authenticating, so most of these
 * tests are about what must *not* come back: an internal note, somebody else's
 * ticket, or anything at all once a link has been withdrawn.
 */
class PortalTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Account $account;
    private AccountContact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
        app(SupportDefaults::class)->ensureFor($this->org->id);

        $this->account = Account::create([
            'organization_id' => $this->org->id,
            'name' => 'Client Ltd', 'reference' => 'ACC-1', 'status' => 'active',
        ]);

        $this->contact = AccountContact::create([
            'organization_id' => $this->org->id,
            'account_id' => $this->account->id,
            'first_name' => 'Ann', 'last_name' => 'Otieno',
            'email' => 'ann@client.co.ke',
        ]);
    }

    private function service(): TicketService { return app(TicketService::class); }

    private function ticketFor(string $email = 'ann@client.co.ke'): Ticket
    {
        return $this->service()->open($this->org->id, [
            'subject'            => 'Internet down',
            'body'               => 'Nothing since this morning.',
            'requester_name'     => 'Ann Otieno',
            'requester_email'    => $email,
            'account_id'         => $this->account->id,
            'account_contact_id' => $this->contact->id,
        ]);
    }

    private function linkTo(Ticket $ticket): string
    {
        return PortalToken::issue([
            'organization_id'   => $this->org->id,
            'support_ticket_id' => $ticket->id,
            'email'             => $ticket->requester_email,
        ])['token'];
    }

    // ── What must never come back ─────────────────────────────────────────────

    /**
     * The expensive mistake. Internal notes are the desk's working record —
     * supplier prices, what has been ruled out, sometimes an opinion about the
     * customer — and one careless serialisation publishes all of it.
     */
    public function test_internal_notes_are_never_shown_to_the_customer(): void
    {
        $ticket = $this->ticketFor();
        $agent  = User::factory()->create();

        $this->service()->reply($ticket, ['body' => 'Engineer on the way.'], $agent);
        $this->service()->reply($ticket, [
            'body' => 'Supplier quoted 40k, do not tell them yet.',
            'visibility' => TicketMessage::VISIBILITY_INTERNAL,
        ], $agent);

        $response = $this->getJson('/api/v1/support/portal/ticket?token=' . $this->linkTo($ticket))
            ->assertOk();

        $response->assertDontSee('Supplier quoted', escape: false);
        $response->assertSee('Engineer on the way', escape: false);
        $this->assertCount(2, $response->json('data.messages'));
    }

    /** The agent's name is ours to share deliberately, not by default. */
    public function test_agent_names_are_not_published(): void
    {
        $ticket = $this->ticketFor();
        $agent  = User::factory()->create(['name' => 'Faith Chebet']);

        $this->service()->reply($ticket, ['body' => 'Looking now.'], $agent);

        $this->getJson('/api/v1/support/portal/ticket?token=' . $this->linkTo($ticket))
            ->assertOk()
            ->assertDontSee('Faith Chebet', escape: false)
            ->assertJsonPath('data.messages.1.from', 'Support');
    }

    /** A withdrawn link is withdrawn, not merely expired later. */
    public function test_a_revoked_link_stops_working(): void
    {
        $ticket = $this->ticketFor();
        $token  = $this->linkTo($ticket);

        $this->getJson('/api/v1/support/portal/ticket?token=' . $token)->assertOk();

        PortalToken::query()->update(['revoked_at' => now()]);

        $this->getJson('/api/v1/support/portal/ticket?token=' . $token)->assertNotFound();
    }

    public function test_an_expired_link_stops_working(): void
    {
        $ticket = $this->ticketFor();
        $token  = $this->linkTo($ticket);

        PortalToken::query()->update(['expires_at' => now()->subDay()]);

        $this->getJson('/api/v1/support/portal/ticket?token=' . $token)->assertNotFound();
    }

    public function test_a_made_up_token_gets_the_same_answer_as_a_real_one_that_expired(): void
    {
        // Telling a caller which it was is free help to somebody guessing.
        $this->getJson('/api/v1/support/portal/ticket?token=nonsense')
            ->assertNotFound()
            ->assertJsonPath('message', 'This link is no longer valid. Ask us for a new one and we will send it over.');
    }

    /** Nothing that opens a ticket belongs in a response. */
    public function test_the_token_is_never_returned(): void
    {
        $ticket = $this->ticketFor();

        $body = $this->getJson('/api/v1/support/portal/ticket?token=' . $this->linkTo($ticket))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('token_hash', $body);
    }

    // ── Seeing your own, and only your own ────────────────────────────────────

    public function test_a_contact_link_lists_only_their_own_tickets(): void
    {
        $mine   = $this->ticketFor();
        $theirs = $this->service()->open($this->org->id, [
            'subject' => 'Somebody else', 'requester_email' => 'other@client.co.ke',
            'account_id' => $this->account->id, 'account_contact_id' => $this->contact->id,
        ]);

        $token = PortalToken::issue([
            'organization_id'    => $this->org->id,
            'account_contact_id' => $this->contact->id,
            'email'              => 'ann@client.co.ke',
        ])['token'];

        $this->getJson('/api/v1/support/portal/tickets?token=' . $token)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', $mine->reference);
    }

    /** A ticket link is one ticket, not a key to the account. */
    public function test_a_ticket_link_cannot_list_the_account(): void
    {
        $ticket = $this->ticketFor();

        $this->getJson('/api/v1/support/portal/tickets?token=' . $this->linkTo($ticket))
            ->assertNotFound();
    }

    // ── Replying ──────────────────────────────────────────────────────────────

    /**
     * A customer's reply must never be recorded as an agent's — it would set a
     * first-response time that never happened.
     */
    public function test_a_portal_reply_is_from_the_customer(): void
    {
        $ticket = $this->ticketFor();

        $this->postJson('/api/v1/support/portal/reply', [
            'token' => $this->linkTo($ticket), 'body' => 'Still down, any news?',
        ])->assertCreated();

        $last = $ticket->fresh()->messages()->orderByDesc('id')->first();

        $this->assertFalse($last->is_from_agent);
        $this->assertSame('portal', $last->source);
        $this->assertNull($ticket->fresh()->first_response_at);
    }

    // ── The catalogue ─────────────────────────────────────────────────────────

    private function mailboxService(): Service
    {
        return Service::create([
            'organization_id' => $this->org->id,
            'name' => 'New mailbox', 'slug' => 'new-mailbox',
            'summary' => 'A mailbox for a new starter',
            'fields' => [
                ['key' => 'full_name', 'label' => 'Name of the new starter', 'type' => 'text', 'required' => true],
                ['key' => 'start_date', 'label' => 'Start date', 'type' => 'date', 'required' => true],
                ['key' => 'notes', 'label' => 'Anything else', 'type' => 'textarea', 'required' => false],
            ],
        ]);
    }

    public function test_a_catalogue_request_becomes_a_ticket_with_its_answers(): void
    {
        $this->mailboxService();

        $token = PortalToken::issue([
            'organization_id'    => $this->org->id,
            'account_contact_id' => $this->contact->id,
            'email'              => 'ann@client.co.ke',
        ])['token'];

        $this->postJson('/api/v1/support/portal/request', [
            'token' => $token, 'service' => 'new-mailbox',
            'summary' => 'Mailbox for Jane Doe',
            'answers' => ['full_name' => 'Jane Doe', 'start_date' => '2026-09-01'],
        ])->assertCreated();

        $ticket = Ticket::where('subject', 'Mailbox for Jane Doe')->firstOrFail();

        $this->assertSame('portal', $ticket->source);
        $this->assertSame($this->account->id, $ticket->account_id);
        // Structured, so it can be reported on rather than read out of prose.
        $this->assertSame('Jane Doe', $ticket->answers()->where('field_key', 'full_name')->value('value'));
    }

    /** The form's own rules, so required cannot mean optional here. */
    public function test_a_required_answer_is_enforced(): void
    {
        $this->mailboxService();

        $token = PortalToken::issue([
            'organization_id'    => $this->org->id,
            'account_contact_id' => $this->contact->id,
            'email'              => 'ann@client.co.ke',
        ])['token'];

        $this->postJson('/api/v1/support/portal/request', [
            'token' => $token, 'service' => 'new-mailbox',
            'answers' => ['full_name' => 'Jane Doe'],
        ])->assertStatus(422);
    }

    /** Editing a question later must not rewrite what a past requester was asked. */
    public function test_the_answer_keeps_the_label_it_was_asked_under(): void
    {
        $service = $this->mailboxService();

        $token = PortalToken::issue([
            'organization_id'    => $this->org->id,
            'account_contact_id' => $this->contact->id,
            'email'              => 'ann@client.co.ke',
        ])['token'];

        $this->postJson('/api/v1/support/portal/request', [
            'token' => $token, 'service' => 'new-mailbox',
            'answers' => ['full_name' => 'Jane Doe', 'start_date' => '2026-09-01'],
        ])->assertCreated();

        $fields = $service->fields;
        $fields[0]['label'] = 'Legal name of the employee';
        $service->update(['fields' => $fields]);

        $this->assertSame(
            'Name of the new starter',
            \App\Models\Support\TicketAnswer::where('field_key', 'full_name')->value('label'),
        );
    }

    // ── The survey ────────────────────────────────────────────────────────────

    public function test_a_survey_is_answered_once(): void
    {
        $ticket = $this->ticketFor();
        $token  = Survey::issue($ticket, 'ann@client.co.ke')['token'];

        $this->postJson('/api/v1/support/portal/survey', [
            'token' => $token, 'rating' => 5, 'satisfaction' => 'yes', 'comment' => 'Quick, thanks.',
        ])->assertOk();

        // Letting somebody revise it turns a satisfaction figure into one that
        // moves after the fact, which is what it must not do.
        $this->postJson('/api/v1/support/portal/survey', [
            'token' => $token, 'rating' => 1,
        ])->assertStatus(409);

        $this->assertSame(5, Survey::firstOrFail()->rating);
    }

    /** A survey on open work rates the wait, not the work. */
    public function test_a_survey_cannot_be_sent_before_the_ticket_is_resolved(): void
    {
        $ticket = $this->ticketFor();
        $user   = $this->signedInAgent();

        $this->postJson("/api/v1/support/tickets/{$ticket->id}/survey", [],
            ['X-Organization' => 'alpha'])->assertStatus(422);

        $resolved = TicketStatus::where('name', 'Resolved')->firstOrFail();
        $this->service()->changeStatus($ticket, $resolved, $user);

        $this->postJson("/api/v1/support/tickets/{$ticket->id}/survey", [],
            ['X-Organization' => 'alpha'])->assertCreated();
    }

    private function signedInAgent(): User
    {
        $user = User::factory()->create();
        $role = \App\Models\Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Desk', 'scope' => 'organization',
            'permissions' => ['support.*'],
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        \Laravel\Sanctum\Sanctum::actingAs($user);

        return $user;
    }
}
