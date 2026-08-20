<?php

namespace Tests\Feature\Support;

use App\Models\Organization;
use App\Models\Role;
use App\Models\Support\Automation;
use App\Models\Support\AutomationRun;
use App\Models\Support\Ticket;
use App\Models\Support\TicketPriority;
use App\Models\Support\TicketStatus;
use App\Models\User;
use App\Services\Support\AutomationEngine;
use App\Services\Support\SupportDefaults;
use App\Services\Support\TicketService;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Rules that do the obvious thing so nobody has to.
 *
 * The two ways this goes badly are a rule that triggers itself, and a rule
 * nobody can explain afterwards. Most of what follows is about those.
 */
class AutomationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
        app(SupportDefaults::class)->ensureFor($this->org->id);
    }

    private function service(): TicketService { return app(TicketService::class); }
    private function engine(): AutomationEngine { return app(AutomationEngine::class); }

    private function rule(array $attrs = []): Automation
    {
        return Automation::create(array_merge([
            'organization_id' => $this->org->id,
            'name'       => 'Route password resets',
            'trigger'    => Automation::ON_CREATED,
            'conditions' => ['keyword' => 'password'],
            'actions'    => ['add_note' => 'Looks like a password reset.'],
            'is_active'  => true,
            'is_dry_run' => false,
        ], $attrs));
    }

    private function open(array $data = []): Ticket
    {
        return $this->service()->open($this->org->id, array_merge([
            'subject' => 'Password reset please', 'body' => 'I cannot get in.',
            'requester_email' => 'ann@client.co.ke',
        ], $data));
    }

    private function statusNamed(string $name): TicketStatus
    {
        return TicketStatus::where('name', $name)->firstOrFail();
    }

    private function signIn(array $permissions): User
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

    // ── It fires ──────────────────────────────────────────────────────────────

    public function test_a_rule_fires_on_a_matching_new_ticket(): void
    {
        $this->rule();

        $ticket = $this->open();

        $this->assertSame(1, AutomationRun::where('support_ticket_id', $ticket->id)->count());
        $this->assertStringContainsString('internal note', AutomationRun::first()->summary);
    }

    public function test_a_rule_leaves_a_ticket_it_does_not_match_alone(): void
    {
        $this->rule();

        $ticket = $this->open(['subject' => 'Printer jammed', 'body' => 'Paper stuck.']);

        $this->assertSame(0, AutomationRun::where('support_ticket_id', $ticket->id)->count());
    }

    /** Every condition that is set must hold; unset ones are ignored. */
    public function test_all_conditions_must_hold(): void
    {
        $this->rule(['conditions' => ['keyword' => 'password', 'sender_domain' => 'other.test']]);

        $this->open();

        $this->assertSame(0, AutomationRun::count());
    }

    // ── It does not eat itself ────────────────────────────────────────────────

    /**
     * The failure that matters. Every action changes a ticket, and changing a
     * ticket is what fires an `updated` rule — so without a guard a rule
     * triggers itself and the first sign is a queue where every ticket has been
     * touched four hundred times.
     */
    public function test_a_rule_that_changes_a_ticket_does_not_trigger_itself(): void
    {
        $this->rule([
            'trigger'    => Automation::ON_UPDATED,
            'conditions' => ['keyword' => 'password'],
            'actions'    => ['add_note' => 'Touched by a rule.'],
            'once_per_ticket' => false,
        ]);

        $ticket = $this->open();
        $this->service()->changeStatus($ticket, $this->statusNamed('In Progress'));

        // One firing for the status change, and no cascade from its own note.
        $this->assertSame(1, AutomationRun::where('support_ticket_id', $ticket->id)->count());
    }

    /** Two rules bouncing off each other is the same failure as one. */
    public function test_two_rules_cannot_trigger_each_other(): void
    {
        $this->rule([
            'name' => 'A', 'trigger' => Automation::ON_UPDATED,
            'conditions' => [], 'actions' => ['add_note' => 'A ran.'], 'once_per_ticket' => false,
        ]);
        $this->rule([
            'name' => 'B', 'trigger' => Automation::ON_UPDATED,
            'conditions' => [], 'actions' => ['add_note' => 'B ran.'], 'once_per_ticket' => false,
        ]);

        $ticket = $this->open();
        $this->service()->changeStatus($ticket, $this->statusNamed('In Progress'));

        $this->assertSame(2, AutomationRun::where('support_ticket_id', $ticket->id)->count());
    }

    // ── Guards ────────────────────────────────────────────────────────────────

    public function test_a_once_per_ticket_rule_does_not_act_twice(): void
    {
        $this->rule([
            'trigger' => Automation::ON_UPDATED, 'conditions' => [],
            'actions' => ['add_note' => 'Once.'], 'once_per_ticket' => true,
        ]);

        $ticket = $this->open();
        $this->service()->changeStatus($ticket, $this->statusNamed('In Progress'));
        $this->service()->changeStatus($ticket->fresh(), $this->statusNamed('With User'));

        $this->assertSame(1, AutomationRun::where('support_ticket_id', $ticket->id)->count());
    }

    /** Chasing somebody hourly is not a follow-up. */
    public function test_a_cooldown_stops_a_rule_acting_again_too_soon(): void
    {
        $rule = $this->rule([
            'trigger' => Automation::ON_UPDATED, 'conditions' => [],
            'actions' => ['add_note' => 'Chased.'],
            'once_per_ticket' => false, 'cooldown_hours' => 24,
        ]);

        $ticket = $this->open();
        $this->service()->changeStatus($ticket, $this->statusNamed('In Progress'));
        $this->service()->changeStatus($ticket->fresh(), $this->statusNamed('With User'));

        $this->assertSame(1, AutomationRun::where('support_ticket_id', $ticket->id)->count());

        // A day later it may act again.
        AutomationRun::query()->update(['fired_at' => now()->subHours(25)]);
        $this->service()->changeStatus($ticket->fresh(), $this->statusNamed('In Progress'));

        $this->assertSame(2, AutomationRun::where('support_ticket_id', $ticket->id)->count());
    }

    // ── Rehearsal ─────────────────────────────────────────────────────────────

    /** A rule that closes tickets should be rehearsed before it goes near any. */
    public function test_a_dry_run_records_what_it_would_do_and_does_nothing(): void
    {
        $this->rule(['is_dry_run' => true, 'actions' => ['set_status' => $this->statusNamed('Closed')->id]]);

        $ticket = $this->open();

        $run = AutomationRun::firstOrFail();

        $this->assertTrue($run->was_dry_run);
        $this->assertStringContainsString('Would set', $run->summary);
        // Untouched.
        $this->assertNotSame($this->statusNamed('Closed')->id, $ticket->fresh()->ticket_status_id);
    }

    /** And an armed rule does it. */
    public function test_an_armed_rule_actually_changes_the_ticket(): void
    {
        $this->rule([
            'is_dry_run' => false,
            'actions' => ['set_priority' => TicketPriority::where('name', 'High')->firstOrFail()->id],
        ]);

        $ticket = $this->open();

        $this->assertSame(
            TicketPriority::where('name', 'High')->firstOrFail()->id,
            $ticket->fresh()->priority_id,
        );
    }

    // ── Scheduled ─────────────────────────────────────────────────────────────

    /**
     * A chase asks how long since anybody said anything, not how old the ticket
     * is — one opened three weeks ago and answered this morning is not stale.
     */
    public function test_a_scheduled_rule_chases_only_what_has_gone_quiet(): void
    {
        $this->rule([
            'trigger' => Automation::ON_SCHEDULE,
            'conditions' => ['state' => 'pending', 'silent_hours' => 72],
            'actions' => ['add_note' => 'Chased the customer.'],
        ]);

        $quiet = $this->open(['subject' => 'Password reset — quiet']);
        $fresh = $this->open(['subject' => 'Password reset — answered today']);

        foreach ([$quiet, $fresh] as $t) {
            $this->service()->changeStatus($t, $this->statusNamed('With User'));
        }

        // Only the first has been silent for days.
        \App\Models\Support\TicketMessage::where('support_ticket_id', $quiet->id)
            ->update(['created_at' => now()->subDays(5)]);

        $this->engine()->runScheduled($this->org->id);

        $this->assertSame(1, AutomationRun::where('support_ticket_id', $quiet->id)->count());
        $this->assertSame(0, AutomationRun::where('support_ticket_id', $fresh->id)->count());
    }

    /** A sweep must not reach into closed work and reopen last year. */
    public function test_a_scheduled_rule_ignores_closed_tickets(): void
    {
        $this->rule([
            'trigger' => Automation::ON_SCHEDULE,
            'conditions' => ['keyword' => 'password'],
            'actions' => ['add_note' => 'Swept.'],
        ]);

        $ticket = $this->open();
        $this->service()->changeStatus($ticket, $this->statusNamed('Closed'));

        AutomationRun::query()->delete();
        $this->engine()->runScheduled($this->org->id);

        $this->assertSame(0, AutomationRun::count());
    }

    // ── Rules that would misbehave are refused ────────────────────────────────

    /** Without a condition it matches every open ticket, every hour. */
    public function test_a_scheduled_rule_with_no_conditions_is_refused(): void
    {
        $this->signIn([Permission::SUPPORT_VIEW, Permission::SUPPORT_MANAGE_CONFIG]);

        $this->postJson('/api/v1/support/automations', [
            'name' => 'Sweep everything', 'trigger' => 'scheduled',
            'conditions' => [], 'actions' => ['add_note' => 'hello'],
        ], ['X-Organization' => 'alpha'])->assertStatus(422);
    }

    public function test_a_repeating_customer_reply_needs_a_cooldown(): void
    {
        $this->signIn([Permission::SUPPORT_VIEW, Permission::SUPPORT_MANAGE_CONFIG]);

        $this->postJson('/api/v1/support/automations', [
            'name' => 'Chase', 'trigger' => 'scheduled',
            'conditions' => ['state' => 'pending'],
            'actions' => ['reply' => 'Any update?'],
            'once_per_ticket' => false,
        ], ['X-Organization' => 'alpha'])->assertStatus(422);
    }

    // ── Explaining itself ─────────────────────────────────────────────────────

    /**
     * "Why did this ticket close itself?" cannot be answered from a rule
     * definition — the rule may have changed since.
     */
    public function test_every_firing_is_readable_against_the_ticket(): void
    {
        $this->rule();
        $ticket = $this->open();
        $this->signIn([Permission::SUPPORT_VIEW]);

        $this->getJson("/api/v1/support/tickets/{$ticket->id}/automation-runs",
            ['X-Organization' => 'alpha'])
            ->assertOk()
            ->assertJsonPath('data.0.automation.name', 'Route password resets')
            ->assertJsonCount(1, 'data');
    }

    /** An automated reply is not somebody answering. */
    public function test_an_automated_reply_does_not_stop_the_response_clock(): void
    {
        $this->rule(['actions' => ['reply' => 'Thanks, we have this.']]);

        $ticket = $this->open();

        $this->assertNull($ticket->fresh()->first_response_at);
    }
}
