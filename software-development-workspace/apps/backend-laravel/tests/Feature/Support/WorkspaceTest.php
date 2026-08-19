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
