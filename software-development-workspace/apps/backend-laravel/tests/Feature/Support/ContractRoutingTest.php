<?php

namespace Tests\Feature\Support;

use App\Models\Account\Account;
use App\Models\Account\AccountContract;
use App\Models\Organization;
use App\Models\OrgBusinessUnit;
use App\Models\OrgPractice;
use App\Models\Role;
use App\Models\Support\ContractEngineer;
use App\Models\Support\ContractSupport;
use App\Models\Support\Engineer;
use App\Models\Support\Escalation;
use App\Models\Support\ServiceCategory;
use App\Models\Support\Team;
use App\Models\Support\Ticket;
use App\Models\Support\TicketEvent;
use App\Models\User;
use App\Services\Support\EscalationService;
use App\Services\Support\RoutingEngine;
use App\Services\Support\SupportDefaults;
use App\Services\Support\TicketService;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tickets go to the engineer who knows the customer.
 *
 * Round-robin and least-busy are gone. Both distributed work by availability
 * and ignored the only thing that reliably makes support faster — an engineer
 * who installed a customer's network is not interchangeable with one who has
 * never seen it.
 */
class ContractRoutingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Account $account;
    private AccountContract $contract;
    private ServiceCategory $network;
    private ServiceCategory $billing;

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

        $this->contract = AccountContract::create([
            'organization_id' => $this->org->id,
            'account_id' => $this->account->id,
            'name' => 'Managed Services', 'reference' => 'CT-1',
            'start_date' => '2026-01-01', 'end_date' => '2027-01-01', 'status' => 'active',
        ]);

        $this->network = ServiceCategory::where('name', 'Network')->firstOrFail();
        $this->billing = ServiceCategory::where('name', 'Billing and contracts')->firstOrFail();
    }

    /** A business unit needs a department, so the fixture makes one. */
    private function businessUnit(string $name): OrgBusinessUnit
    {
        $department = \App\Models\Department::create([
            'organization_id' => $this->org->id, 'name' => $name . ' Dept',
        ]);

        return OrgBusinessUnit::create([
            'organization_id' => $this->org->id,
            'department_id'   => $department->id,
            'name'            => $name,
        ]);
    }

    private function service(): TicketService { return app(TicketService::class); }
    private function routing(): RoutingEngine { return app(RoutingEngine::class); }

    private function open(array $data = []): Ticket
    {
        return $this->service()->open($this->org->id, array_merge([
            'subject'         => 'Link down',
            'requester_email' => 'ann@client.co.ke',
            'account_id'      => $this->account->id,
        ], $data));
    }

    private function engineerOn(User $user, ?ServiceCategory $category = null, string $role = 'primary'): ContractEngineer
    {
        return ContractEngineer::create([
            'organization_id'     => $this->org->id,
            'account_contract_id' => $this->contract->id,
            'user_id'             => $user->id,
            'role'                => $role,
            'service_category_id' => $category?->id,
        ]);
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

    // ── Contract-based assignment ─────────────────────────────────────────────

    /** The whole point of the revision. */
    public function test_a_ticket_goes_to_the_contract_engineer_for_its_category(): void
    {
        $networkEngineer = User::factory()->create(['name' => 'Network Engineer']);
        $cloudEngineer   = User::factory()->create(['name' => 'Cloud Engineer']);

        $this->engineerOn($networkEngineer, $this->network);
        $this->engineerOn($cloudEngineer, ServiceCategory::where('name', 'Cloud')->firstOrFail());

        $ticket = $this->open(['service_category_id' => $this->network->id]);

        $this->assertSame($networkEngineer->id, $ticket->fresh()->assigned_to);
    }

    /** The generalist on the contract, where no category-specific engineer exists. */
    public function test_it_falls_back_to_the_contract_engineer_with_no_category(): void
    {
        $generalist = User::factory()->create();
        $this->engineerOn($generalist);

        $ticket = $this->open(['service_category_id' => $this->network->id]);

        $this->assertSame($generalist->id, $ticket->fresh()->assigned_to);
    }

    public function test_the_primary_engineer_is_preferred_over_the_secondary(): void
    {
        $secondary = User::factory()->create();
        $primary   = User::factory()->create();

        $this->engineerOn($secondary, $this->network, 'secondary');
        $this->engineerOn($primary, $this->network, 'primary');

        $this->assertSame($primary->id, $this->open(['service_category_id' => $this->network->id])
            ->fresh()->assigned_to);
    }

    /** A lapsed contract is not a support entitlement. */
    public function test_an_expired_contract_does_not_route(): void
    {
        $engineer = User::factory()->create();
        $this->engineerOn($engineer);
        $this->contract->update(['status' => 'expired']);

        $this->assertNull($this->open()->fresh()->assigned_to);
    }

    // ── Falling back ──────────────────────────────────────────────────────────

    public function test_with_no_engineer_it_goes_to_the_contract_team(): void
    {
        $team = Team::create([
            'organization_id' => $this->org->id, 'name' => 'Managed', 'slug' => 'managed',
        ]);

        ContractSupport::create([
            'organization_id' => $this->org->id,
            'account_contract_id' => $this->contract->id,
            'primary_team_id' => $team->id,
        ]);

        $ticket = $this->open()->fresh();

        $this->assertSame($team->id, $ticket->support_team_id);
        $this->assertNull($ticket->assigned_to);
    }

    /** The practice that owns the discipline, when the contract says nothing. */
    public function test_it_falls_back_to_the_practice_queue(): void
    {
        $bu = $this->businessUnit('Infrastructure');
        $practice = OrgPractice::create([
            'organization_id' => $this->org->id, 'business_unit_id' => $bu->id, 'name' => 'Networks',
        ]);

        $this->network->update(['org_practice_id' => $practice->id]);

        $queue = Team::create([
            'organization_id' => $this->org->id, 'name' => 'Network Queue', 'slug' => 'network-queue',
            'org_practice_id' => $practice->id,
        ]);

        $ticket = $this->open(['service_category_id' => $this->network->id])->fresh();

        $this->assertSame($queue->id, $ticket->support_team_id);
    }

    /**
     * A ticket nobody can place still has to land somewhere a person looks.
     * The old module left those unassigned, which is how things sat for a week.
     */
    public function test_anything_unplaceable_reaches_the_service_desk(): void
    {
        $ticket = $this->service()->open($this->org->id, ['subject' => 'Who are you?'])->fresh();

        $desk = Team::where('is_service_desk', true)->firstOrFail();

        $this->assertSame($desk->id, $ticket->support_team_id);
    }

    /** A billing query is support work and not an engineer's problem. */
    public function test_non_technical_work_is_not_forced_onto_an_engineer(): void
    {
        $engineer = User::factory()->create();
        $this->engineerOn($engineer, $this->network);

        $ticket = $this->open(['service_category_id' => $this->billing->id])->fresh();

        $this->assertNull($ticket->assigned_to);
        $this->assertFalse($this->billing->is_technical);
    }

    // ── Manual assignment wins ────────────────────────────────────────────────

    /** A rule that reroutes work somebody picked up is a rule that loses it. */
    public function test_routing_never_overrides_a_manual_assignment(): void
    {
        $contractEngineer = User::factory()->create();
        $chosen           = User::factory()->create();
        $this->engineerOn($contractEngineer, $this->network);

        $ticket = $this->open([
            'service_category_id' => $this->network->id,
            'assigned_to'         => $chosen->id,
        ]);

        $this->assertSame($chosen->id, $ticket->fresh()->assigned_to);

        // And re-running routing leaves it alone.
        $this->routing()->route($ticket->fresh());
        $this->assertSame($chosen->id, $ticket->fresh()->assigned_to);
    }

    /** Only a deliberate, authorised re-route may move it. */
    public function test_a_forced_reroute_can_move_an_assigned_ticket(): void
    {
        $contractEngineer = User::factory()->create();
        $chosen           = User::factory()->create();
        $this->engineerOn($contractEngineer, $this->network);

        $ticket = $this->open([
            'service_category_id' => $this->network->id, 'assigned_to' => $chosen->id,
        ]);

        $this->routing()->route($ticket->fresh(), force: true);

        $this->assertSame($contractEngineer->id, $ticket->fresh()->assigned_to);
    }

    // ── Suggestions, not automatic distribution ───────────────────────────────

    /** Contract knowledge outranks being idle. */
    public function test_suggestions_rank_contract_engineers_first(): void
    {
        $onContract = User::factory()->create(['name' => 'Knows them']);
        $inPractice = User::factory()->create(['name' => 'Free but new']);

        $bu = $this->businessUnit('Infra');
        $practice = OrgPractice::create([
            'organization_id' => $this->org->id, 'business_unit_id' => $bu->id, 'name' => 'Networks',
        ]);
        $this->network->update(['org_practice_id' => $practice->id]);

        $this->engineerOn($onContract, $this->network);
        Engineer::create([
            'organization_id' => $this->org->id, 'user_id' => $inPractice->id,
            'org_practice_id' => $practice->id,
        ]);

        $ticket = $this->open(['service_category_id' => $this->network->id]);

        $suggestions = $this->routing()->suggest($ticket->fresh());

        $this->assertSame($onContract->id, $suggestions[0]['user_id']);
        $this->assertStringContainsString('contract', $suggestions[0]['reason']);
    }

    /** Workload is shown, and it cannot outweigh knowing the customer. */
    public function test_a_busy_contract_engineer_still_outranks_an_idle_stranger(): void
    {
        $busy   = User::factory()->create();
        $idle   = User::factory()->create();

        $bu = $this->businessUnit('Infra');
        $practice = OrgPractice::create([
            'organization_id' => $this->org->id, 'business_unit_id' => $bu->id, 'name' => 'Networks',
        ]);
        $this->network->update(['org_practice_id' => $practice->id]);

        $this->engineerOn($busy, $this->network);
        Engineer::create([
            'organization_id' => $this->org->id, 'user_id' => $idle->id, 'org_practice_id' => $practice->id,
        ]);

        foreach (range(1, 6) as $i) {
            $this->open(['subject' => "Existing {$i}", 'assigned_to' => $busy->id]);
        }

        $suggestions = $this->routing()->suggest($this->open()->fresh());

        $this->assertSame($busy->id, $suggestions[0]['user_id']);
        $this->assertGreaterThan(0, $suggestions[0]['open']);
    }

    // ── The analyst ───────────────────────────────────────────────────────────

    /** Ordinary agents cannot take work off each other. */
    public function test_reassignment_needs_its_own_permission(): void
    {
        $ticket = $this->open();
        $this->signIn([Permission::SUPPORT_VIEW, Permission::SUPPORT_UPDATE]);

        $this->postJson("/api/v1/support/tickets/{$ticket->id}/reassign", [
            'assigned_to' => User::factory()->create()->id, 'reason' => 'Wrong queue',
        ], ['X-Organization' => 'alpha'])->assertForbidden();
    }

    public function test_an_analyst_can_reassign_and_it_is_recorded(): void
    {
        $ticket = $this->open();
        $to     = User::factory()->create();
        $this->signIn([Permission::SUPPORT_VIEW, Permission::SUPPORT_TRIAGE]);

        $this->postJson("/api/v1/support/tickets/{$ticket->id}/reassign", [
            'assigned_to' => $to->id, 'reason' => 'Needs the network team',
        ], ['X-Organization' => 'alpha'])->assertOk();

        $this->assertSame($to->id, $ticket->fresh()->assigned_to);

        // "Who sent this to me and why" has to be answerable.
        $event = TicketEvent::where('support_ticket_id', $ticket->id)
            ->whereIn('event', [TicketEvent::ASSIGNED, TicketEvent::REASSIGNED])
            ->where('automatic', false)->firstOrFail();

        $this->assertSame('Needs the network team', $event->reason);
    }

    /** A reason is not optional — the next person needs to know what was tried. */
    public function test_a_reassignment_without_a_reason_is_refused(): void
    {
        $ticket = $this->open();
        $this->signIn([Permission::SUPPORT_VIEW, Permission::SUPPORT_TRIAGE]);

        $this->postJson("/api/v1/support/tickets/{$ticket->id}/reassign", [
            'assigned_to' => User::factory()->create()->id,
        ], ['X-Organization' => 'alpha'])->assertStatus(422);
    }

    // ── Escalation ────────────────────────────────────────────────────────────

    public function test_escalating_moves_ownership_and_keeps_the_history(): void
    {
        $engineer  = User::factory()->create();
        $specialist = User::factory()->create();

        $ticket = $this->open(['assigned_to' => $engineer->id]);

        $escalation = app(EscalationService::class)->escalate($ticket, [
            'level' => Escalation::LEVEL_SPECIALIST,
            'reason' => 'BGP session flapping, needs the core team',
            'to_user_id' => $specialist->id,
        ], $engineer);

        $fresh = $ticket->fresh();

        $this->assertSame($specialist->id, $fresh->assigned_to);
        $this->assertSame($specialist->id, $fresh->escalation_owner_id);
        // Where it came from is on the record, not lost.
        $this->assertSame($engineer->id, $escalation->from_user_id);
    }

    /** Escalating is not an excuse — the customer's clock keeps running. */
    public function test_the_sla_clock_survives_an_escalation(): void
    {
        $ticket = $this->open([
            'impact_id'  => \App\Models\Support\TicketImpact::where('name', 'High')->firstOrFail()->id,
            'urgency_id' => \App\Models\Support\TicketUrgency::where('name', 'High')->firstOrFail()->id,
        ]);

        // Give it a clock to survive.
        $sla = \App\Models\Support\Sla::create([
            'organization_id' => $this->org->id, 'name' => 'Standard', 'is_default' => true,
        ]);
        \App\Models\Support\SlaTarget::create([
            'support_sla_id' => $sla->id,
            'priority_id' => \App\Models\Support\TicketPriority::where('name', 'Critical')->firstOrFail()->id,
            'response_minutes' => 60, 'resolution_minutes' => 480,
        ]);
        app(\App\Services\Support\SlaClock::class)->attach($ticket->fresh());

        $due = $ticket->fresh()->sla->resolution_due_at;

        app(EscalationService::class)->escalate($ticket->fresh(), [
            'level' => Escalation::LEVEL_TEAM_LEAD,
            'reason' => 'Needs the lead',
            'to_user_id' => User::factory()->create()->id,
        ]);

        $this->assertEquals($due, $ticket->fresh()->sla->resolution_due_at);
    }

    /** "Escalated" on its own tells the next person nothing. */
    public function test_an_escalation_without_a_reason_is_refused(): void
    {
        $ticket = $this->open();
        $this->signIn([Permission::SUPPORT_VIEW, Permission::SUPPORT_ESCALATE]);

        $this->postJson("/api/v1/support/tickets/{$ticket->id}/escalate", [
            'level' => 'specialist', 'to_user_id' => User::factory()->create()->id,
        ], ['X-Organization' => 'alpha'])->assertStatus(422);
    }

    /** Handing it back moves the work and the knowledge. */
    public function test_resolving_an_escalation_returns_it_with_the_outcome(): void
    {
        $engineer   = User::factory()->create();
        $specialist = User::factory()->create();
        $ticket     = $this->open(['assigned_to' => $engineer->id]);

        $escalation = app(EscalationService::class)->escalate($ticket, [
            'level' => Escalation::LEVEL_SPECIALIST, 'reason' => 'Needs core',
            'to_user_id' => $specialist->id,
        ], $engineer);

        app(EscalationService::class)->resolve($escalation, 'Replaced the SFP. Link stable.', $specialist);

        $fresh = $ticket->fresh();

        $this->assertSame($engineer->id, $fresh->assigned_to);
        $this->assertNull($fresh->escalation_owner_id);
        $this->assertNotNull($escalation->fresh()->resolved_at);
    }

    // ── The desk board ────────────────────────────────────────────────────────

    public function test_the_desk_board_shows_what_needs_placing_and_chasing(): void
    {
        $this->service()->open($this->org->id, ['subject' => 'Nobody has this']);
        $this->signIn([Permission::SUPPORT_VIEW, Permission::SUPPORT_TRIAGE]);

        $this->getJson('/api/v1/support/desk', ['X-Organization' => 'alpha'])
            ->assertOk()
            ->assertJsonStructure(['unassigned', 'at_risk', 'waiting', 'mine', 'open_escalations']);
    }
}
