<?php

namespace Tests\Feature\Account;

use App\Models\Account\Account;
use App\Models\Account\AccountActivity;
use App\Models\Account\AccountContract;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\Account\DefaultReferenceData;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What the customer header has to be able to say.
 *
 * The redesigned page leads with four figures — lifetime won, open pipeline,
 * next renewal, last contact — and none of the last two existed. These prove
 * they are computed from records rather than typed in, and that money stays
 * behind the commercials permission when the counts do not.
 */
class AccountOverviewTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
        DefaultReferenceData::seedFor($this->org->id);

        $this->account = Account::create([
            'organization_id' => $this->org->id, 'name' => 'AAR Insurance', 'status' => 'active',
        ]);
    }

    private function actor(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Test', 'scope' => 'organization', 'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);

        return $user;
    }

    private function reader(): User
    {
        return $this->actor([Permission::ACCOUNTS_VIEW, Permission::ACCOUNTS_UPDATE]);
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/accounts/{$this->account->id}{$path}?org={$this->org->slug}";
    }

    private function contract(array $attributes = []): AccountContract
    {
        return AccountContract::create(array_merge([
            'organization_id' => $this->org->id, 'account_id' => $this->account->id,
            'name' => 'Group Medical', 'start_date' => '2025-10-01', 'end_date' => '2026-09-30',
            'status' => 'active', 'contract_value' => 4_200_000,
        ], $attributes));
    }

    // ── Renewal ───────────────────────────────────────────────────────────

    public function test_the_next_renewal_is_the_soonest_contract_still_to_fall_due(): void
    {
        $this->reader();
        $this->contract(['name' => 'Later', 'end_date' => now()->addDays(200)->toDateString()]);
        $this->contract(['name' => 'Sooner', 'end_date' => now()->addDays(31)->toDateString()]);

        $renewal = $this->getJson($this->url('/summary'))->assertOk()->json('data.renewal');

        $this->assertSame('Sooner', $renewal['name']);
        $this->assertSame(31, $renewal['days_out']);
    }

    public function test_an_expiry_already_gone_is_not_a_renewal(): void
    {
        // "Renews in -40 days" is worse than saying nothing: a renewal is a
        // date somebody has to act before, and that one is a lapse.
        $this->reader();
        $this->contract(['end_date' => now()->subDays(40)->toDateString()]);

        $this->assertNull($this->getJson($this->url('/summary'))->assertOk()->json('data.renewal'));
    }

    public function test_a_completed_contract_is_not_waiting_to_renew(): void
    {
        $this->reader();
        $this->contract(['end_date' => now()->addDays(10)->toDateString(), 'completed_at' => now()]);

        $this->assertNull($this->getJson($this->url('/summary'))->assertOk()->json('data.renewal'));
    }

    // ── Last contact ──────────────────────────────────────────────────────

    public function test_the_last_contact_names_the_channel_and_the_person(): void
    {
        // "4 days ago" tells you the account is warm but not who to ask.
        $this->reader();

        $employee = Employee::create([
            'employee_number' => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id, 'first_name' => 'Wanjiru', 'last_name' => 'Kamau',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
        ]);

        AccountActivity::create([
            'organization_id' => $this->org->id, 'account_id' => $this->account->id,
            'kind' => 'manual', 'type' => 'call', 'body' => 'Renewal chase',
            'occurred_at' => now()->subDays(4), 'employee_id' => $employee->id,
        ]);

        $last = $this->getJson($this->url('/summary'))->assertOk()->json('data.last_contact');

        $this->assertSame('call', $last['type']);
        $this->assertSame('Wanjiru Kamau', $last['person']);
    }

    public function test_a_customer_nobody_has_spoken_to_says_so_rather_than_guessing(): void
    {
        $this->reader();

        $this->assertNull($this->getJson($this->url('/summary'))->assertOk()->json('data.last_contact'));
    }

    // ── Contracts and counts ──────────────────────────────────────────────

    public function test_the_contract_totals_come_with_the_summary(): void
    {
        $this->reader();
        $this->contract(['end_date' => now()->addDays(31)->toDateString()]);
        $this->contract(['contract_value' => 8_400_000, 'status' => 'expired',
                         'end_date' => now()->subDay()->toDateString()]);

        $contracts = $this->getJson($this->url('/summary'))->assertOk()->json('data.contracts');

        $this->assertEqualsWithDelta(12_600_000, $contracts['contracted_value'], 0.01);
        $this->assertSame(1, $contracts['active']);
        $this->assertSame(2, $contracts['total']);
    }

    public function test_the_tab_badges_are_counted_not_fetched(): void
    {
        $this->reader();
        $this->contract();

        $counts = $this->getJson($this->url('/summary'))->assertOk()->json('data.counts');

        $this->assertSame(1, $counts['contracts']);
        $this->assertArrayHasKey('projects', $counts);
        $this->assertArrayHasKey('support', $counts);
    }

    // ── Projects ──────────────────────────────────────────────────────────

    public function test_the_work_delivered_for_a_customer_is_listed(): void
    {
        // Both tabs said "not built yet" while the data sat there: projects and
        // tickets have named an account for a long time, and the customer
        // record simply never read back down the link.
        $this->reader();

        $project = \App\Models\Project\Project::create([
            'organization_id' => $this->org->id, 'account_id' => $this->account->id,
            'name' => 'SD-WAN rollout', 'reference' => 'PRJ-118',
            'status' => 'execution', 'deadline' => '2026-09-30',
        ]);

        $row = $this->getJson($this->url('/projects'))->assertOk()->json('data.0');

        $this->assertSame($project->id, $row['id']);
        $this->assertSame('PRJ-118', $row['reference']);
        $this->assertSame('2026-09-30', $row['deadline']);
    }

    public function test_progress_is_counted_from_the_tasks_not_stored(): void
    {
        // A stored percentage would start disagreeing with the task board the
        // day somebody ticked something off.
        $this->reader();

        $project = \App\Models\Project\Project::create([
            'organization_id' => $this->org->id, 'account_id' => $this->account->id,
            'name' => 'SD-WAN rollout', 'status' => 'execution',
        ]);

        foreach ([true, true, false, false] as $done) {
            \App\Models\Task\Task::create([
                'organization_id' => $this->org->id, 'project_id' => $project->id,
                'title' => 'T' . fake()->unique()->numberBetween(1, 99999),
                'status' => $done ? 'done' : 'todo',
                'completed_at' => $done ? now() : null,
            ]);
        }

        $row = $this->getJson($this->url('/projects'))->assertOk()->json('data.0');

        $this->assertSame(4, $row['tasks_total']);
        $this->assertSame(2, $row['tasks_done']);
        $this->assertSame(50, $row['progress']);
    }

    public function test_a_project_nobody_has_planned_has_no_progress_rather_than_nought(): void
    {
        // 0% reads as "started and got nowhere"; null reads as "no plan yet".
        $this->reader();

        \App\Models\Project\Project::create([
            'organization_id' => $this->org->id, 'account_id' => $this->account->id,
            'name' => 'Not planned', 'status' => 'kickoff',
        ]);

        $this->assertNull($this->getJson($this->url('/projects'))->assertOk()->json('data.0.progress'));
    }

    // ── Support ───────────────────────────────────────────────────────────

    public function test_a_response_stamped_within_a_minute_is_the_importer_not_a_person(): void
    {
        // 102 imported tickets carry first_response_at == created_at, and on
        // the largest account every remaining "response" lands 1–25 seconds
        // out. Averaging those in publishes a fabricated zero.
        $this->reader();

        $status = \App\Models\Support\TicketStatus::create([
            'organization_id' => $this->org->id, 'name' => 'Open', 'slug' => 'open-' . fake()->unique()->numberBetween(1, 99999),
            'state' => 'open', 'colour' => '#000', 'position' => 1, 'is_active' => true,
        ]);

        $raised = now()->subHours(3);

        // One measured at 30 minutes; one stamped at the moment it was raised;
        // one stamped 12 seconds later, which is the importer, not a person.
        foreach ([
            [$raised, $raised->copy()->addMinutes(30)],
            [$raised, $raised],
            [$raised, $raised->copy()->addSeconds(12)],
        ] as [$created, $responded]) {
            $ticket = \App\Models\Support\Ticket::create([
                'organization_id' => $this->org->id, 'account_id' => $this->account->id,
                'reference' => 'SD-' . fake()->unique()->numberBetween(10000, 99999),
                'subject' => 'Line down', 'ticket_status_id' => $status->id,
                'first_response_at' => $responded,
            ]);
            $ticket->forceFill(['created_at' => $created])->save();
        }

        $stats = $this->getJson($this->url('/support'))->assertOk()->json('stats');

        $this->assertSame(30, $stats['avg_response_minutes']);
        $this->assertSame(1, $stats['responded']);
    }

    public function test_a_desk_nobody_has_answered_reports_no_average_rather_than_zero(): void
    {
        $this->reader();

        $this->assertNull($this->getJson($this->url('/support'))->assertOk()->json('stats.avg_response_minutes'));
    }

    // ── The list ──────────────────────────────────────────────────────────

    public function test_each_row_carries_what_the_customer_is_worth_and_what_is_happening(): void
    {
        // The list used to be six columns of which four were empty on most
        // rows, so a book of 68 customers looked identical top to bottom.
        $this->reader();
        $this->contract();

        \App\Models\Project\Project::create([
            'organization_id' => $this->org->id, 'account_id' => $this->account->id,
            'name' => 'Rollout', 'status' => 'execution',
        ]);

        $row = collect($this->getJson("/api/v1/accounts?org={$this->org->slug}")->assertOk()->json('data'))
            ->firstWhere('id', $this->account->id);

        $this->assertSame(1, $row['projects']);
        $this->assertSame(1, $row['active_contracts']);
        $this->assertSame(0, $row['open_deals']);
    }

    public function test_the_book_totals_come_with_the_page(): void
    {
        // Across the whole book, not the page — the sentence must not change
        // when somebody turns to page two.
        $this->reader();

        foreach (range(1, 3) as $i) {
            Account::create([
                'organization_id' => $this->org->id, 'name' => "Other {$i}", 'status' => 'active',
            ]);
        }

        $totals = $this->getJson("/api/v1/accounts?org={$this->org->slug}&per_page=2")
            ->assertOk()->json('totals');

        $this->assertSame(4, $totals['customers']);
        $this->assertSame(4, $totals['active']);
        // Nobody has spoken to any of them, which is the number worth acting on.
        $this->assertSame(4, $totals['never_contacted']);
        $this->assertSame(4, $totals['unowned']);
    }

    public function test_the_counts_are_visible_when_the_values_are_not(): void
    {
        // "Three deals in play" is account management; what they are worth is
        // commercial, and aggregating it does not make it less so.
        $this->actor([Permission::ACCOUNTS_VIEW]);

        $row = collect($this->getJson("/api/v1/accounts?org={$this->org->slug}")->assertOk()->json('data'))
            ->firstWhere('id', $this->account->id);

        $this->assertSame(0, $row['open_deals']);
        $this->assertNull($row['open_pipeline']);
    }

    // ── Following ─────────────────────────────────────────────────────────

    public function test_a_customer_can_be_followed_and_unfollowed(): void
    {
        $this->reader();

        $this->assertFalse($this->getJson($this->url())->assertOk()->json('data.is_following'));

        $this->postJson($this->url('/follow'))->assertOk()->assertJsonPath('data.following', true);
        $this->assertTrue($this->getJson($this->url())->assertOk()->json('data.is_following'));

        $this->deleteJson($this->url('/follow'))->assertOk()->assertJsonPath('data.following', false);
        $this->assertFalse($this->getJson($this->url())->assertOk()->json('data.is_following'));
    }

    public function test_following_twice_is_the_same_as_following_once(): void
    {
        $this->reader();

        $this->postJson($this->url('/follow'))->assertOk();
        $this->postJson($this->url('/follow'))->assertOk();

        $this->assertDatabaseCount('account_followers', 1);
    }

    public function test_following_is_personal(): void
    {
        // Two people watching the same account is the normal case, and one of
        // them unfollowing must not stop the other being told.
        $first = $this->reader();
        $this->postJson($this->url('/follow'))->assertOk();

        $this->reader();   // a different user
        $this->assertFalse($this->getJson($this->url())->assertOk()->json('data.is_following'));

        Sanctum::actingAs($first);
        $this->assertTrue($this->getJson($this->url())->assertOk()->json('data.is_following'));
    }
}
