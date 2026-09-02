<?php

namespace Tests\Feature\Task;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\Project\Project;
use App\Models\Role;
use App\Models\Task\Task;
use App\Models\Task\TaskEscalation;
use App\Models\Task\TaskPause;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Where the work is, and whose fault the waiting was. */
class TaskAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
        $this->project = Project::create(['organization_id' => $this->org->id, 'name' => 'Rollout']);
        $this->actor([Permission::TASKS_VIEW, Permission::TASKS_CREATE, Permission::TASKS_UPDATE]);
    }

    private function actor(array $permissions): Employee
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'T', 'scope' => 'organization', 'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();

        $employee = Employee::create([
            'employee_number'         => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id,
            'first_name' => 'Test', 'last_name' => 'Person',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
            'user_id' => $user->id,
        ]);
        Sanctum::actingAs($user);

        return $employee->fresh();
    }

    private function url(string $query = ''): string
    {
        return "/api/v1/tasks/analytics?org={$this->org->slug}{$query}";
    }

    private function task(array $attrs = []): Task
    {
        return Task::create($attrs + [
            'organization_id' => $this->org->id,
            'project_id'      => $this->project->id,
            'category'        => 'project',
            'title'           => 'Cut over the link',
        ]);
    }

    private function pause(Task $t, string $reason, string $category, ?int $daysAgo = 4, bool $open = true): TaskPause
    {
        return TaskPause::create([
            'organization_id' => $this->org->id, 'task_id' => $t->id,
            'category' => $category, 'reason' => $reason,
            'responsible_party' => TaskPause::REASONS[$category]['party'],
            'paused_at' => now()->subDays($daysAgo),
            'resumed_at' => $open ? null : now(),
        ]);
    }

    private function analytics(string $query = ''): array
    {
        return $this->getJson($this->url($query))->assertOk()->json('data');
    }

    // ── Health ────────────────────────────────────────────────────────────

    public function test_it_counts_the_state_of_the_work(): void
    {
        $this->task(['status' => 'todo']);
        $this->task(['status' => 'in_progress']);
        $this->task(['status' => Task::DONE, 'completed_at' => now()]);
        $late = $this->task(['deadline' => now()->subWeek()->toDateString()]);
        $held = $this->task(['deadline' => now()->subWeek()->toDateString()]);
        $this->pause($held, 'awaiting_signoff', 'pending_customer');

        $h = $this->analytics()['health'];

        $this->assertSame(5, $h['total']);
        $this->assertSame(4, $h['open']);
        $this->assertSame(1, $h['closed']);
        $this->assertSame(1, $h['paused']);
        $this->assertSame(2, $h['overdue']);
        // One of the two late ones is parked on a client, so only one is ours.
        $this->assertSame(1, $h['overdue_on_us']);
        $this->assertSame($late->id, $late->id);
    }

    public function test_a_task_whose_deadline_has_moved_counts_as_extended(): void
    {
        $t = $this->task(['deadline' => now()->addMonth()->toDateString()]);
        $t->extensions()->create([
            'organization_id' => $this->org->id,
            'new_deadline' => now()->addMonths(2)->toDateString(),
            'reason' => 'slip', 'impact' => 'some',
        ]);
        $this->task();

        $this->assertSame(1, $this->analytics()['health']['extended']);
    }

    /** A sub-task is real work, but counting it beside its parent double-counts. */
    public function test_sub_tasks_are_not_counted_beside_their_parents(): void
    {
        $parent = $this->task();
        $this->task(['parent_task_id' => $parent->id, 'title' => 'A step']);

        $this->assertSame(1, $this->analytics()['health']['total']);
    }

    // ── Dependency ────────────────────────────────────────────────────────

    public function test_it_says_who_the_open_work_is_sitting_with(): void
    {
        $this->task(['responsibility' => 'customer', 'deadline' => now()->subDays(3)->toDateString()]);
        $this->task(['responsibility' => 'customer']);
        $this->task(['responsibility' => 'vendor']);
        $this->task(); // nobody has said

        $d = $this->analytics()['dependency'];

        $customer = collect($d['by_responsibility'])->firstWhere('party', 'customer');
        $this->assertSame(2, $customer['open']);
        $this->assertSame(1, $customer['overdue']);
        $this->assertEqualsWithDelta(3, $customer['avg_days_overdue'], 0.6);

        // Sorted by how much is sitting there.
        $this->assertSame('customer', $d['by_responsibility'][0]['party']);
        // An unanswered question is not the same as "us".
        $this->assertSame(1, $d['unattributed']);
    }

    public function test_open_pauses_are_grouped_by_who_the_wait_is_on(): void
    {
        $this->pause($this->task(), 'awaiting_signoff', 'pending_customer', 6);
        $this->pause($this->task(), 'awaiting_payment', 'pending_customer', 2);
        $this->pause($this->task(), 'awaiting_delivery', 'pending_vendor', 1);
        // A finished wait is history, not something anybody is still stuck on.
        $this->pause($this->task(), 'awaiting_licensing', 'pending_vendor', 9, open: false);

        $rows = collect($this->analytics()['dependency']['open_pauses']);

        $customer = $rows->firstWhere('category', 'pending_customer');
        $this->assertSame(2, $customer['count']);
        $this->assertEqualsWithDelta(8.0, $customer['days'], 0.2);
        // Ordered by days lost, so the worst reads first.
        $this->assertSame('pending_customer', $rows->first()['category']);
        $this->assertSame(1, $rows->firstWhere('category', 'pending_vendor')['count']);
    }

    // ── Accountability ────────────────────────────────────────────────────

    public function test_bottlenecks_are_where_the_time_actually_went(): void
    {
        $this->pause($this->task(), 'awaiting_signoff', 'pending_customer', 10, open: false);
        $this->pause($this->task(), 'awaiting_signoff', 'pending_customer', 8, open: false);
        $this->pause($this->task(), 'awaiting_delivery', 'pending_vendor', 2, open: false);

        $top = $this->analytics()['accountability']['bottlenecks'][0];

        $this->assertSame('awaiting_signoff', $top['reason']);
        $this->assertSame(2, $top['count']);
        $this->assertEqualsWithDelta(18.0, $top['days'], 0.3);
    }

    public function test_extension_trends_are_reported_by_month_and_by_task(): void
    {
        $a = $this->task(['title' => 'The one that keeps moving']);
        foreach (range(1, 3) as $i) {
            $a->extensions()->create([
                'organization_id' => $this->org->id,
                'previous_deadline' => now()->addDays($i * 7)->toDateString(),
                'new_deadline' => now()->addDays(($i + 1) * 7)->toDateString(),
                'reason' => 'slip', 'impact' => 'some',
            ]);
        }
        $this->task()->extensions()->create([
            'organization_id' => $this->org->id,
            'previous_deadline' => now()->toDateString(),
            'new_deadline' => now()->addDays(2)->toDateString(),
            'reason' => 'slip', 'impact' => 'some',
        ]);

        $e = $this->analytics()['accountability']['extensions'];

        $this->assertSame(4, $e['total']);
        $this->assertSame(2, $e['tasks_affected']);
        $this->assertSame(now()->format('Y-m'), $e['by_month'][0]['month']);
        // Only the repeat offender is worth naming.
        $this->assertCount(1, $e['most_moved']);
        $this->assertSame('The one that keeps moving', $e['most_moved'][0]['title']);
        $this->assertSame(3, $e['most_moved'][0]['moves']);
    }

    /**
     * The difference between a team that is slow and a team that is blocked.
     */
    public function test_punctuality_reads_twice_once_net_of_the_waiting(): void
    {
        // On time.
        $this->task([
            'deadline' => now()->subDays(2)->toDateString(),
            'status' => Task::DONE, 'completed_at' => now()->subDays(3),
        ]);
        // Five days late, but it sat with a client for six of them.
        $blocked = $this->task([
            'deadline' => now()->subDays(6)->toDateString(),
            'status' => Task::DONE, 'completed_at' => now()->subDay(),
        ]);
        TaskPause::create([
            'organization_id' => $this->org->id, 'task_id' => $blocked->id,
            'category' => 'pending_customer', 'reason' => 'awaiting_approval',
            'responsible_party' => 'customer',
            'paused_at' => now()->subDays(7), 'resumed_at' => now()->subDay(),
        ]);

        $p = $this->analytics()['accountability']['punctuality'];

        $this->assertSame(2, $p['finished']);
        $this->assertSame(1, $p['on_time']);
        $this->assertEquals(50, $p['rate']);
        // Take the waiting out and both landed.
        $this->assertEquals(100, $p['rate_net_of_waiting']);
    }

    public function test_punctuality_says_nothing_where_nothing_had_a_deadline(): void
    {
        $this->task(['status' => Task::DONE, 'completed_at' => now()]);

        $this->assertNull($this->analytics()['accountability']['punctuality']['rate']);
    }

    // ── Escalations ───────────────────────────────────────────────────────

    /** A rung reached with nobody on it is an escalation that went nowhere. */
    public function test_it_counts_the_rungs_that_reached_nobody(): void
    {
        $t = $this->task(['deadline' => now()->subDays(20)->toDateString()]);
        foreach ([
            [TaskEscalation::LEVEL_OWNER, null],
            [TaskEscalation::LEVEL_PM, null],
        ] as [$level, $to]) {
            TaskEscalation::create([
                'organization_id' => $this->org->id, 'task_id' => $t->id,
                'trigger' => TaskEscalation::TRIGGER_OVERDUE, 'level' => $level,
                'to_employee_id' => $to, 'detail' => 'late', 'escalated_at' => now(),
            ]);
        }

        $e = $this->analytics()['escalations'];

        $this->assertSame(2, $e['total']);
        $this->assertSame(1, $e['tasks']);
        $this->assertSame(2, $e['unreachable'], 'Both went nowhere, and that is the point of counting.');
        $this->assertSame('Past its deadline', $e['by_trigger'][0]['label']);
    }

    // ── Filters and access ────────────────────────────────────────────────

    public function test_it_narrows_to_one_project(): void
    {
        $this->task();
        $other = Project::create(['organization_id' => $this->org->id, 'name' => 'Elsewhere']);

        $this->assertSame(0, $this->analytics("&project_id={$other->id}")['health']['total']);
    }

    /** Company-wide figures need company-wide visibility. */
    public function test_somebody_who_only_sees_their_own_tasks_cannot_read_the_totals(): void
    {
        $this->actor([Permission::TASKS_VIEW_OWN]);

        $this->getJson($this->url())->assertForbidden();
    }
}
