<?php

namespace Tests\Feature\Support;

use App\Models\Account\Account;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Support\PriorityMatrix;
use App\Models\Support\Ticket;
use App\Models\Support\TicketImpact;
use App\Models\Support\TicketMessage;
use App\Models\Support\TicketStatus;
use App\Models\Support\TicketUrgency;
use App\Models\User;
use App\Services\Support\SupportDefaults;
use App\Services\Support\TicketService;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The service desk core.
 *
 * Most of these lock down semantics the SLA engine will read next phase. The
 * old module's SLA reporting never worked partly because a status could not say
 * whether the ticket was waiting on us, and partly because "first response" was
 * a button rather than an event — both are settled here so phase three has
 * something true to measure.
 */
class TicketCoreTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
    }

    /** The vocabulary, for tests that need to look a level up before opening. */
    private function seedDefaults(): void
    {
        app(SupportDefaults::class)->ensureFor($this->org->id);
    }

    private function impact(string $name): TicketImpact
    {
        $this->seedDefaults();

        return TicketImpact::where('name', $name)->firstOrFail();
    }

    private function urgency(string $name): TicketUrgency
    {
        $this->seedDefaults();

        return TicketUrgency::where('name', $name)->firstOrFail();
    }

    private function service(): TicketService
    {
        return app(TicketService::class);
    }

    private function open(array $data = [], ?User $actor = null): Ticket
    {
        return $this->service()->open($this->org->id, array_merge([
            'subject'         => 'Internet down at the branch',
            'body'            => 'Nothing since this morning.',
            'requester_name'  => 'Ann Otieno',
            'requester_email' => 'ann@client.co.ke',
            'source'          => 'email',
        ], $data), $actor);
    }

    private function statusNamed(string $name): TicketStatus
    {
        return TicketStatus::where('name', $name)->firstOrFail();
    }

    private function agent(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Desk', 'scope' => 'organization', 'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);

        return $user;
    }

    // ── A desk exists without anybody setting one up ──────────────────────────

    /**
     * An organisation created after this shipped must not be the one whose
     * first ticket fails because no status exists to put it in.
     */
    public function test_a_new_organisation_gets_a_working_desk_on_first_use(): void
    {
        $ticket = $this->open();

        $this->assertNotNull($ticket->ticket_status_id);
        $this->assertSame('New', $ticket->status->name);
        $this->assertSame(17, TicketStatus::count());
        $this->assertSame(9, PriorityMatrix::count());
    }

    public function test_setting_up_twice_does_not_duplicate_the_vocabulary(): void
    {
        app(SupportDefaults::class)->ensureFor($this->org->id);
        app(SupportDefaults::class)->ensureFor($this->org->id);

        $this->assertSame(17, TicketStatus::count());
    }

    // ── Status carries a state ────────────────────────────────────────────────

    /**
     * The column phase three reads. "With Supplier" must be machine-readable as
     * waiting-on-somebody-else, or the clock cannot know to stop.
     */
    public function test_waiting_statuses_declare_themselves_as_pending(): void
    {
        $this->open();

        $this->assertSame(TicketStatus::STATE_PENDING, $this->statusNamed('With Supplier')->state);
        $this->assertSame(TicketStatus::STATE_PENDING, $this->statusNamed('With User')->state);
        $this->assertSame(TicketStatus::STATE_OPEN, $this->statusNamed('In Progress')->state);
        $this->assertTrue($this->statusNamed('With User')->stopsTheClock());
        $this->assertFalse($this->statusNamed('In Progress')->stopsTheClock());
    }

    /** A declined request is finished, not fixed. */
    public function test_rejected_is_a_closed_state_not_a_resolved_one(): void
    {
        $this->open();

        $this->assertSame(TicketStatus::STATE_CLOSED, $this->statusNamed('Rejected')->state);
    }

    // ── Priority is derived ───────────────────────────────────────────────────

    public function test_priority_comes_from_impact_and_urgency(): void
    {
        $ticket = $this->open([
            'impact_id'  => $this->impact('High')->id,
            'urgency_id' => $this->urgency('High')->id,
        ]);

        $this->assertSame('Critical', $ticket->priority->name);
    }

    /**
     * A printer nobody else uses is urgent to one person and low impact to
     * everybody — it lands on Medium rather than jumping the queue.
     */
    public function test_low_impact_and_high_urgency_does_not_become_critical(): void
    {
        $ticket = $this->open([
            'impact_id'  => $this->impact('Low')->id,
            'urgency_id' => $this->urgency('High')->id,
        ]);

        $this->assertSame('Medium', $ticket->priority->name);
    }

    /**
     * An email arrives with neither, and inventing "Medium" would make an
     * untriaged ticket look triaged.
     */
    public function test_an_untriaged_ticket_has_no_priority_rather_than_a_guessed_one(): void
    {
        $this->assertNull($this->open()->priority_id);
    }

    public function test_reclassifying_moves_the_priority_with_it(): void
    {
        $ticket = $this->open([
            'impact_id'  => $this->impact('Low')->id,
            'urgency_id' => $this->urgency('Low')->id,
        ]);
        $this->assertSame('Low', $ticket->priority->name);

        $updated = $this->service()->reclassify($ticket, [
            'impact_id'  => $this->impact('High')->id,
            'urgency_id' => $this->urgency('High')->id,
        ]);

        $this->assertSame('Critical', $updated->priority->name);
    }

    // ── First response is an event, not a button ──────────────────────────────

    /** The old module measured a button an agent pressed. This measures a reply. */
    public function test_the_first_public_agent_reply_sets_the_first_response(): void
    {
        $agent  = User::factory()->create();
        $ticket = $this->open();

        $this->assertNull($ticket->first_response_at);

        $this->service()->reply($ticket, ['body' => 'Looking into it now.'], $agent);

        $this->assertNotNull($ticket->fresh()->first_response_at);
    }

    /** An internal note is not a response to the customer. */
    public function test_an_internal_note_does_not_count_as_a_first_response(): void
    {
        $agent  = User::factory()->create();
        $ticket = $this->open();

        $this->service()->reply($ticket, [
            'body' => 'Router looks dead — checking stock.',
            'visibility' => TicketMessage::VISIBILITY_INTERNAL,
        ], $agent);

        $this->assertNull($ticket->fresh()->first_response_at);
    }

    /** The customer writing again is not us responding. */
    public function test_a_customer_message_does_not_count_as_a_first_response(): void
    {
        $ticket = $this->open();

        $this->service()->reply($ticket, [
            'body' => 'Any update?', 'is_from_agent' => false, 'source' => 'email',
        ]);

        $this->assertNull($ticket->fresh()->first_response_at);
    }

    /** It is the *first* response, so a later reply must not move it. */
    public function test_the_first_response_is_not_overwritten_by_later_replies(): void
    {
        $agent  = User::factory()->create();
        $ticket = $this->open();

        $this->service()->reply($ticket, ['body' => 'On it.'], $agent);
        $first = $ticket->fresh()->first_response_at;

        $this->service()->reply($ticket, ['body' => 'Still on it.'], $agent);

        $this->assertEquals($first, $ticket->fresh()->first_response_at);
    }

    /** The opening description is the start of the conversation, not a column. */
    public function test_the_opening_description_becomes_the_first_message(): void
    {
        $ticket = $this->open();

        $this->assertSame(1, $ticket->messages()->count());
        $this->assertFalse($ticket->messages()->first()->is_from_agent);
    }

    // ── Timestamps stay honest ────────────────────────────────────────────────

    public function test_resolving_stamps_the_time_and_closing_stamps_both(): void
    {
        $ticket = $this->open();

        $resolved = $this->service()->changeStatus($ticket, $this->statusNamed('Resolved'));
        $this->assertNotNull($resolved->resolved_at);
        $this->assertNull($resolved->closed_at);

        $closed = $this->service()->changeStatus($resolved, $this->statusNamed('Closed'));
        $this->assertNotNull($closed->closed_at);
        $this->assertNotNull($closed->resolved_at);
    }

    /**
     * A resolved_at on a ticket that is open again is a false fact — and it is
     * the fact every resolution-time report would be built on.
     */
    public function test_reopening_clears_the_resolution_timestamp(): void
    {
        $ticket = $this->open();
        $this->service()->changeStatus($ticket, $this->statusNamed('Resolved'));

        $reopened = $this->service()->changeStatus($ticket->fresh(), $this->statusNamed('Re-Opened'));

        $this->assertNull($reopened->resolved_at);
        $this->assertNull($reopened->closed_at);
    }

    // ── References ────────────────────────────────────────────────────────────

    public function test_references_are_sequential_and_readable(): void
    {
        $year = now()->format('Y');

        $this->assertSame("SD-{$year}-00001", $this->open()->reference);
        $this->assertSame("SD-{$year}-00002", $this->open()->reference);
    }

    /** Two tenants must never collide on a number quoted down a phone line. */
    public function test_two_organisations_number_their_tickets_independently(): void
    {
        $this->open();

        $other = Organization::create(['name' => 'Beta', 'slug' => 'beta']);
        app()->instance('current.organization_id', $other->id);

        $theirs = $this->service()->open($other->id, ['subject' => 'Theirs']);

        $this->assertSame('SD-' . now()->format('Y') . '-00001', $theirs->reference);
    }

    // ── Endpoints ─────────────────────────────────────────────────────────────

    public function test_the_list_hides_closed_tickets_unless_asked(): void
    {
        $open = $this->open(['subject' => 'Still broken']);
        $done = $this->open(['subject' => 'All fixed']);
        $this->service()->changeStatus($done, $this->statusNamed('Closed'));

        $this->agent([Permission::SUPPORT_VIEW]);

        $this->getJson('/api/v1/support/tickets', ['X-Organization' => 'alpha'])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $open->id);

        $this->getJson('/api/v1/support/tickets?all=1', ['X-Organization' => 'alpha'])
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_an_agent_can_open_a_ticket_through_the_api(): void
    {
        $this->agent([Permission::SUPPORT_VIEW, Permission::SUPPORT_CREATE]);

        $this->postJson('/api/v1/support/tickets', [
            'subject' => 'Link down in Mombasa', 'body' => 'Since 9am.',
            'requester_email' => 'ops@client.co.ke',
        ], ['X-Organization' => 'alpha'])
            ->assertCreated()
            ->assertJsonPath('data.reference', 'SD-' . now()->format('Y') . '-00001');
    }

    /** Closing fixes an SLA outcome, so it is gated apart from replying. */
    public function test_closing_needs_its_own_permission(): void
    {
        $ticket = $this->open();
        $this->agent([Permission::SUPPORT_VIEW, Permission::SUPPORT_UPDATE]);

        $this->postJson("/api/v1/support/tickets/{$ticket->id}/status", [
            'ticket_status_id' => $this->statusNamed('Closed')->id,
        ], ['X-Organization' => 'alpha'])->assertForbidden();

        // The same agent may still park it with the customer.
        $this->postJson("/api/v1/support/tickets/{$ticket->id}/status", [
            'ticket_status_id' => $this->statusNamed('With User')->id,
        ], ['X-Organization' => 'alpha'])->assertOk();
    }

    public function test_a_ticket_in_another_organisation_is_not_reachable(): void
    {
        $ticket = $this->open();

        $other = Organization::create(['name' => 'Beta', 'slug' => 'beta']);
        $user  = $this->agent([Permission::SUPPORT_VIEW]);
        $user->roles()->attach($user->roles->first()->id, ['organization_id' => $other->id]);
        $user->forgetCachedRoles();

        $this->getJson("/api/v1/support/tickets/{$ticket->id}", ['X-Organization' => 'beta'])
            ->assertNotFound();
    }

    // ── Seeing only your own ──────────────────────────────────────────────────

    /**
     * `support.view_own` used to read exactly like `support.view`.
     *
     * The policy passed on either and nothing narrowed afterwards, so a
     * permission whose entire purpose is to limit somebody to their own tickets
     * showed them everybody's.
     */
    public function test_view_own_shows_only_your_own_tickets(): void
    {
        $mine    = $this->open(['subject' => 'Mine']);
        $theirs  = $this->open(['subject' => 'Somebody else\'s']);

        $user = $this->agent([Permission::SUPPORT_VIEW_OWN]);
        $mine->update(['assigned_to' => $user->id]);

        $this->getJson('/api/v1/support/tickets', ['X-Organization' => 'alpha'])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);
    }

    /** And the record itself is refused, not merely hidden from the list. */
    public function test_view_own_cannot_open_somebody_elses_ticket(): void
    {
        $theirs = $this->open(['subject' => 'Not yours']);
        $this->agent([Permission::SUPPORT_VIEW_OWN]);

        $this->getJson("/api/v1/support/tickets/{$theirs->id}", ['X-Organization' => 'alpha'])
            ->assertForbidden();
    }

    /** A requester with a login sees the ticket they raised. */
    public function test_the_person_who_raised_it_can_see_it(): void
    {
        $user   = $this->agent([Permission::SUPPORT_VIEW_OWN]);
        $ticket = $this->open(['requester_user_id' => $user->id]);

        $this->getJson("/api/v1/support/tickets/{$ticket->id}", ['X-Organization' => 'alpha'])
            ->assertOk();
    }

    /** Full visibility is unaffected. */
    public function test_support_view_still_sees_everything(): void
    {
        $this->open(['subject' => 'One']);
        $this->open(['subject' => 'Two']);
        $this->agent([Permission::SUPPORT_VIEW]);

        $this->getJson('/api/v1/support/tickets', ['X-Organization' => 'alpha'])
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    // ── Time ──────────────────────────────────────────────────────────────────

    /**
     * Captured from the first release, carrying no rate and no invoice. Time is
     * easy to ignore if it is there and impossible to reconstruct if it is not.
     */
    public function test_time_is_logged_and_totalled_from_the_entries(): void
    {
        $ticket = $this->open();
        $agent  = $this->agent([Permission::SUPPORT_VIEW, Permission::SUPPORT_LOG_TIME]);

        $this->postJson("/api/v1/support/tickets/{$ticket->id}/time", [
            'minutes' => 45, 'note' => 'On site',
        ], ['X-Organization' => 'alpha'])->assertCreated();

        $this->postJson("/api/v1/support/tickets/{$ticket->id}/time", [
            'minutes' => 30, 'under_contract' => false,
        ], ['X-Organization' => 'alpha'])->assertCreated()
            ->assertJsonPath('minutes_logged', 75);
    }

    // ── Accounts ──────────────────────────────────────────────────────────────

    /** A ticket belongs to a client, and that link is the one reporting needs. */
    public function test_a_ticket_can_be_attached_to_an_account(): void
    {
        $account = Account::create([
            'organization_id' => $this->org->id,
            'name' => 'Client Ltd', 'reference' => 'ACC-1', 'status' => 'active',
        ]);

        $ticket = $this->open(['account_id' => $account->id]);

        $this->assertSame('Client Ltd', $ticket->fresh()->account->name);
    }
}
