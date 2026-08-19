<?php

namespace Tests\Feature\Support;

use App\Models\Organization;
use App\Models\Role;
use App\Models\Support\AssignmentRule;
use App\Models\Support\CannedResponse;
use App\Models\Support\SavedView;
use App\Models\Support\Team;
use App\Models\Support\TeamMember;
use App\Models\Support\Ticket;
use App\Models\Support\TicketMessage;
use App\Models\Support\TicketStatus;
use App\Models\User;
use App\Services\Support\MajorIncidents;
use App\Services\Support\SupportDefaults;
use App\Services\Support\TicketService;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The desk as somewhere to work.
 *
 * The old module delegated assignment to the tasks module, so a ticket was
 * unowned until somebody made a task out of it and everything sat in one shared
 * pile. And one outage was forty separate tickets, worked forty times.
 */
class WorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
        app(SupportDefaults::class)->ensureFor($this->org->id);

        $this->team = Team::create([
            'organization_id' => $this->org->id, 'name' => 'Network', 'slug' => 'network',
        ]);
    }

    private function service(): TicketService { return app(TicketService::class); }

    private function open(array $data = []): Ticket
    {
        return $this->service()->open($this->org->id, array_merge([
            'subject'         => 'Internet down',
            'body'            => 'Nothing this morning.',
            'requester_email' => 'ann@client.co.ke',
        ], $data));
    }

    private function member(bool $accepts = true): User
    {
        $user = User::factory()->create();
        TeamMember::create([
            'support_team_id' => $this->team->id,
            'user_id' => $user->id, 'accepts_assignment' => $accepts,
        ]);

        return $user;
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

    // ── Routing ───────────────────────────────────────────────────────────────

    public function test_a_rule_routes_a_ticket_to_a_team(): void
    {
        AssignmentRule::create([
            'organization_id' => $this->org->id, 'name' => 'Network mail',
            'match_domain' => 'client.co.ke',
            'assign_team_id' => $this->team->id, 'assign_mode' => AssignmentRule::MODE_NONE,
        ]);

        $this->assertSame($this->team->id, $this->open()->fresh()->support_team_id);
    }

    /** An unset condition is ignored, not treated as a requirement. */
    public function test_a_rule_with_one_condition_matches_on_that_alone(): void
    {
        AssignmentRule::create([
            'organization_id' => $this->org->id, 'name' => 'Keyword',
            'match_keyword' => 'down',
            'assign_team_id' => $this->team->id,
        ]);

        $this->assertSame($this->team->id, $this->open()->fresh()->support_team_id);

        // Not matching the rule does not mean landing nowhere: anything
        // unplaced reaches the service desk, where a person will see it.
        $desk = Team::where('is_service_desk', true)->firstOrFail();

        $this->assertSame($desk->id, $this->open(['subject' => 'Please add a mailbox', 'body' => 'thanks'])
            ->fresh()->support_team_id);
    }

    public function test_the_first_matching_rule_wins(): void
    {
        $second = Team::create([
            'organization_id' => $this->org->id, 'name' => 'Second', 'slug' => 'second',
        ]);

        AssignmentRule::create([
            'organization_id' => $this->org->id, 'name' => 'First', 'position' => 1,
            'match_keyword' => 'down', 'assign_team_id' => $this->team->id,
        ]);
        AssignmentRule::create([
            'organization_id' => $this->org->id, 'name' => 'Second', 'position' => 2,
            'match_keyword' => 'down', 'assign_team_id' => $second->id,
        ]);

        $this->assertSame($this->team->id, $this->open()->fresh()->support_team_id);
    }





    // ── Major incidents ───────────────────────────────────────────────────────

    public function test_tickets_join_to_a_parent(): void
    {
        $parent = $this->open(['subject' => 'Core link down']);
        $child  = $this->open(['subject' => 'No internet']);

        app(MajorIncidents::class)->merge($child, $parent);

        $this->assertSame($parent->id, $child->fresh()->parent_ticket_id);
        $this->assertNotNull($child->fresh()->merged_at);
    }

    /** Chains cannot be walked or counted; one level only. */
    public function test_a_child_cannot_be_joined_to_another_child(): void
    {
        $grandparent = $this->open(['subject' => 'A']);
        $parent      = $this->open(['subject' => 'B']);
        $child       = $this->open(['subject' => 'C']);

        app(MajorIncidents::class)->merge($parent, $grandparent);

        $this->expectException(\RuntimeException::class);
        app(MajorIncidents::class)->merge($child, $parent);
    }

    /**
     * One update reaches everybody, as a real reply on each of their own
     * tickets — which is what they look at and what their SLA is measured on.
     */
    public function test_a_broadcast_answers_every_joined_ticket(): void
    {
        $agent  = User::factory()->create();
        $parent = $this->open(['subject' => 'Core link down']);
        $a      = $this->open(['subject' => 'No internet']);
        $b      = $this->open(['subject' => 'Cannot reach the server']);

        app(MajorIncidents::class)->merge($a, $parent);
        app(MajorIncidents::class)->merge($b, $parent);

        $result = app(MajorIncidents::class)->broadcast($parent, 'Fibre cut. Engineer on site.', $agent);

        $this->assertSame(2, $result['children']);

        foreach ([$a, $b] as $child) {
            $last = $child->fresh()->messages()->orderByDesc('id')->first();
            $this->assertSame(TicketMessage::VISIBILITY_PUBLIC, $last->visibility);
            $this->assertStringContainsString('Fibre cut', $last->body);
            // And it counts as their first response.
            $this->assertNotNull($child->fresh()->first_response_at);
        }
    }

    /** Merging is a statement about cause, not a way to hide thirty-nine breaches. */
    public function test_a_child_keeps_its_own_first_response_mark(): void
    {
        $parent = $this->open(['subject' => 'Core link down']);
        $child  = $this->open(['subject' => 'No internet']);

        app(MajorIncidents::class)->merge($child, $parent);

        // The internal note about the join is not an answer to the customer.
        $this->assertNull($child->fresh()->first_response_at);
    }

    public function test_resolving_the_parent_can_resolve_the_children(): void
    {
        $parent = $this->open(['subject' => 'Core link down']);
        $child  = $this->open(['subject' => 'No internet']);
        app(MajorIncidents::class)->merge($child, $parent);

        $count = app(MajorIncidents::class)->resolveAll($parent, $this->statusNamed('Resolved'));

        $this->assertSame(2, $count);
        $this->assertNotNull($child->fresh()->resolved_at);
    }

    // ── Working the queue ─────────────────────────────────────────────────────

    /**
     * Applied one at a time through the service, so the clock and the
     * timestamps behave as they would if somebody had opened each ticket.
     */
    public function test_a_bulk_status_change_goes_through_the_normal_rules(): void
    {
        $a = $this->open(['subject' => 'One']);
        $b = $this->open(['subject' => 'Two']);
        $this->signIn([Permission::SUPPORT_VIEW, Permission::SUPPORT_UPDATE]);

        $this->postJson('/api/v1/support/tickets/bulk', [
            'ticket_ids' => [$a->id, $b->id],
            'action'     => 'status',
            'ticket_status_id' => $this->statusNamed('Resolved')->id,
        ], ['X-Organization' => 'alpha'])->assertOk()->assertJsonPath('updated', 2);

        $this->assertNotNull($a->fresh()->resolved_at);
        $this->assertNotNull($b->fresh()->resolved_at);
    }

    /** Closing in bulk still needs the permission that closing needs. */
    public function test_a_bulk_close_needs_the_close_permission(): void
    {
        $a = $this->open();
        $this->signIn([Permission::SUPPORT_VIEW, Permission::SUPPORT_UPDATE]);

        $this->postJson('/api/v1/support/tickets/bulk', [
            'ticket_ids' => [$a->id], 'action' => 'status',
            'ticket_status_id' => $this->statusNamed('Closed')->id,
        ], ['X-Organization' => 'alpha'])->assertForbidden();
    }

    /** A count that does not match the selection needs a reason. */
    public function test_unreachable_tickets_are_named_rather_than_dropped(): void
    {
        $a = $this->open();
        $this->signIn([Permission::SUPPORT_VIEW, Permission::SUPPORT_UPDATE, Permission::SUPPORT_ASSIGN]);

        $this->postJson('/api/v1/support/tickets/bulk', [
            'ticket_ids' => [$a->id, 999999],
            'action'     => 'assign',
            'assigned_to' => User::factory()->create()->id,
        ], ['X-Organization' => 'alpha'])
            ->assertOk()
            ->assertJsonPath('updated', 1)
            ->assertJsonPath('skipped.0', 999999);
    }

    // ── Splitting ─────────────────────────────────────────────────────────────

    /**
     * "The laptop is slow, and while I have you, we need a mailbox." Two pieces
     * of work with two owners and two clocks; worked as one they close when the
     * slower half finishes.
     */
    public function test_a_ticket_splits_into_a_second_one(): void
    {
        $ticket = $this->open(['subject' => 'Laptop slow and new starter mailbox']);
        $agent  = User::factory()->create();
        $second = $this->service()->reply($ticket, ['body' => 'Also the mailbox please.'], $agent);

        $fresh = $this->service()->split($ticket, [
            'subject'     => 'New starter mailbox',
            'message_ids' => [$second->id],
        ], $agent);

        $this->assertNotSame($ticket->id, $fresh->id);
        $this->assertSame($ticket->id, $fresh->split_from_ticket_id);
        $this->assertSame('New starter mailbox', $fresh->subject);
    }

    /**
     * The original keeps everything. Moving messages out would leave replies to
     * something nobody can see — and the customer's own copy still has them.
     */
    public function test_splitting_leaves_the_original_intact(): void
    {
        $ticket = $this->open();
        $agent  = User::factory()->create();
        $extra  = $this->service()->reply($ticket, ['body' => 'Second problem.'], $agent);

        $before = $ticket->messages()->count();

        $this->service()->split($ticket, ['subject' => 'Second problem', 'message_ids' => [$extra->id]], $agent);

        // Everything it had, plus the internal note saying where the rest went.
        $this->assertSame($before + 1, $ticket->fresh()->messages()->count());
        $this->assertTrue($ticket->fresh()->messages()
            ->where('visibility', TicketMessage::VISIBILITY_INTERNAL)
            ->get()->contains(fn ($m) => str_contains($m->body, 'Split:')));
    }

    /** The customer and their contract come with it — same person asking. */
    public function test_the_split_keeps_the_customer_and_contract(): void
    {
        $account = \App\Models\Account\Account::create([
            'organization_id' => $this->org->id,
            'name' => 'Client Ltd', 'reference' => 'ACC-9', 'status' => 'active',
        ]);

        $ticket = $this->open(['account_id' => $account->id, 'requester_name' => 'Ann Otieno']);

        $fresh = $this->service()->split($ticket, ['subject' => 'Other thing'], User::factory()->create());

        $this->assertSame($account->id, $fresh->account_id);
        $this->assertSame('Ann Otieno', $fresh->requester_name);
    }

    /** A split is not a major incident, and must not appear as one. */
    public function test_a_split_is_not_a_parent_child_relationship(): void
    {
        $ticket = $this->open();
        $fresh  = $this->service()->split($ticket, ['subject' => 'Other thing'], User::factory()->create());

        $this->assertNull($fresh->parent_ticket_id);
        $this->assertSame(0, $ticket->fresh()->children()->count());
        $this->assertSame(1, $ticket->fresh()->splits()->count());
    }

    /** Usually the point: the other half belongs to a different discipline. */
    public function test_the_split_can_be_routed_somewhere_else(): void
    {
        $mailboxes = \App\Models\Support\ServiceCategory::where('name', 'Cloud')->firstOrFail();

        $ticket = $this->open();
        $fresh  = $this->service()->split($ticket, [
            'subject' => 'New starter mailbox',
            'service_category_id' => $mailboxes->id,
        ], User::factory()->create());

        $this->assertSame($mailboxes->id, $fresh->service_category_id);
        $this->assertSame(Team::where('name', 'Cloud Support')->value('id'), $fresh->support_team_id);
    }

    public function test_a_split_without_a_subject_is_refused(): void
    {
        $ticket = $this->open();
        $this->signIn([Permission::SUPPORT_VIEW, Permission::SUPPORT_UPDATE]);

        $this->postJson("/api/v1/support/tickets/{$ticket->id}/split", [],
            ['X-Organization' => 'alpha'])->assertStatus(422);
    }

    // ── Canned responses ──────────────────────────────────────────────────────

    public function test_a_canned_response_fills_in_what_the_system_knows(): void
    {
        $ticket = $this->open(['requester_name' => 'Ann Otieno']);
        $canned = CannedResponse::create([
            'organization_id' => $this->org->id,
            'title' => 'Acknowledged',
            'body'  => 'Hello {{requester}}, we have logged {{reference}}.',
        ]);

        $this->signIn([Permission::SUPPORT_VIEW, Permission::SUPPORT_UPDATE]);

        $this->postJson("/api/v1/support/workspace/canned/{$canned->id}/use",
            ['ticket_id' => $ticket->id], ['X-Organization' => 'alpha'])
            ->assertOk()
            ->assertJsonPath('body', "Hello Ann Otieno, we have logged {$ticket->reference}.");

        // Counted, so the ones that read well rise up the list on their own.
        $this->assertSame(1, $canned->fresh()->use_count);
    }

    /** "Hello ," is worse than a sentence somebody typed. */
    public function test_a_missing_requester_name_falls_back_rather_than_leaving_a_gap(): void
    {
        $ticket = $this->open(['requester_name' => null]);
        $canned = CannedResponse::create([
            'organization_id' => $this->org->id, 'title' => 'Hi', 'body' => 'Hello {{requester}}.',
        ]);

        $this->assertSame('Hello there.', $canned->render($ticket));
    }

    // ── Rules that would do nothing ───────────────────────────────────────────

    public function test_a_specific_assignment_without_a_person_is_refused(): void
    {
        $this->signIn([Permission::SUPPORT_VIEW, Permission::SUPPORT_MANAGE_CONFIG]);

        $this->postJson('/api/v1/support/workspace/rules', [
            'name' => 'Broken', 'assign_mode' => 'specific',
        ], ['X-Organization' => 'alpha'])->assertStatus(422);
    }


    // ── Queues and the hierarchy ──────────────────────────────────────────────

    /**
     * The standard queues exist from the first ticket, wired to the categories
     * they handle. Without this the practice fallback needs an org hierarchy
     * nobody has built yet, and everything lands on the service desk.
     */
    public function test_the_standard_queues_are_created_and_wired_to_categories(): void
    {
        $names = Team::pluck('name');

        $this->assertTrue($names->contains('Service Desk'));
        $this->assertTrue($names->contains('Network Support'));
        $this->assertTrue($names->contains('Vendor Escalation'));

        $network = \App\Models\Support\ServiceCategory::where('name', 'Network')->firstOrFail();

        $this->assertSame(
            Team::where('name', 'Network Support')->value('id'),
            $network->default_team_id,
        );
    }

    /** A category's own queue is what routing reaches when no contract applies. */
    public function test_a_categorised_ticket_reaches_its_queue(): void
    {
        $network = \App\Models\Support\ServiceCategory::where('name', 'Network')->firstOrFail();

        $ticket = $this->service()->open($this->org->id, [
            'subject' => 'Link down', 'service_category_id' => $network->id,
        ])->fresh();

        $this->assertSame(Team::where('name', 'Network Support')->value('id'), $ticket->support_team_id);
    }

    /** Vendor escalation is a queue, and it says so rather than being found by name. */
    public function test_the_vendor_queue_is_marked_as_one(): void
    {
        $this->assertTrue(Team::where('name', 'Vendor Escalation')->firstOrFail()->is_vendor_queue);
    }

    /** Two desk queues would make routing's last resort a matter of insertion order. */
    public function test_only_one_queue_can_be_the_service_desk(): void
    {
        $this->signIn([Permission::SUPPORT_VIEW, Permission::SUPPORT_MANAGE_CONFIG]);

        $other = Team::where('name', 'Network Support')->firstOrFail();

        $this->putJson("/api/v1/support/workspace/teams/{$other->id}",
            ['is_service_desk' => true], ['X-Organization' => 'alpha'])->assertOk();

        $this->assertSame(1, Team::where('is_service_desk', true)->count());
        $this->assertSame($other->id, Team::where('is_service_desk', true)->value('id'));
    }

    /**
     * The top-up runs on every setup call, so it must not undo an
     * administrator who deliberately pointed a category somewhere else.
     */
    public function test_topping_up_the_queues_does_not_overwrite_a_chosen_queue(): void
    {
        $network = \App\Models\Support\ServiceCategory::where('name', 'Network')->firstOrFail();
        $chosen  = Team::where('name', 'Cloud Support')->firstOrFail();

        $network->update(['default_team_id' => $chosen->id]);

        // Anything that triggers setup again.
        app(SupportDefaults::class)->ensureFor($this->org->id);

        $this->assertSame($chosen->id, $network->fresh()->default_team_id);
    }

    // ── Workload ──────────────────────────────────────────────────────────────

    /**
     * A load report that omits the person carrying fifteen tickets because
     * nobody filled in their profile is worse than no report.
     */
    public function test_the_workload_view_includes_anybody_holding_work(): void
    {
        $holder = User::factory()->create(['name' => 'Not set up as an engineer']);
        $ticket = $this->open();
        $ticket->update(['assigned_to' => $holder->id]);

        $this->signIn([Permission::SUPPORT_VIEW, Permission::SUPPORT_TRIAGE]);

        $this->getJson('/api/v1/support/workspace/workload', ['X-Organization' => 'alpha'])
            ->assertOk()
            ->assertJsonFragment(['user_id' => $holder->id, 'open' => 1]);
    }

    // ── Saved queues ──────────────────────────────────────────────────────────

    /** A personal view is one person's; a shared one belongs to the desk. */
    public function test_somebody_cannot_delete_another_persons_view(): void
    {
        $mine = $this->signIn([Permission::SUPPORT_VIEW]);

        $theirs = SavedView::create([
            'organization_id' => $this->org->id,
            'user_id' => User::factory()->create()->id,
            'name' => 'Theirs', 'filters' => ['state' => 'open'],
        ]);

        $this->deleteJson("/api/v1/support/workspace/views/{$theirs->id}", [],
            ['X-Organization' => 'alpha'])->assertForbidden();
    }
}
