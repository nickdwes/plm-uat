<?php

namespace Tests\Feature\Task;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Task\Task;
use App\Models\Task\TaskRecurrence;
use App\Models\User;
use App\Services\Task\TaskGenerator;
use App\Support\Authorization\Permission;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tasks the system raises rather than somebody typing them.
 *
 * A third of the legacy's 9,055 tasks came from somewhere other than a form —
 * Project 1,004 all carrying a project, Support 34 all carrying a ticket, oem
 * 41 all carrying a certificate. It kept a `source` column saying so and never
 * read it, so nobody could tell a task they had been given from one they wrote.
 *
 * The thing actually being proved here is idempotency. A generator that runs on
 * a schedule runs twice, and without a key saying "this exact thing already
 * produced this exact task" it makes a duplicate every time.
 */
class TaskGenerationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function coordinator(): Employee
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Test', 'scope' => 'organization',
            'permissions' => [
                Permission::TASKS_VIEW, Permission::TASKS_CREATE,
                Permission::TASKS_UPDATE, Permission::TASKS_DELETE,
            ],
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();

        $employee = Employee::create([
            'employee_number'         => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id,
            'first_name' => 'Test', 'last_name' => 'Person',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
        ]);
        $employee->update(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        return $employee->fresh();
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/tasks{$path}?org={$this->org->slug}";
    }

    private function generator(): TaskGenerator
    {
        return app(TaskGenerator::class);
    }

    private function schedule(array $attrs = []): TaskRecurrence
    {
        return TaskRecurrence::create($attrs + [
            'organization_id' => $this->org->id,
            'title'     => 'Monthly firewall rule review',
            'frequency' => 'monthly',
            'starts_on' => now()->subMonths(2)->startOfMonth()->toDateString(),
        ]);
    }

    // ── Idempotency ───────────────────────────────────────────────────────

    /**
     * The whole point. A generator that runs on a schedule runs twice — after
     * a deploy, after a retry, after somebody reruns it by hand.
     */
    public function test_generating_the_same_thing_twice_makes_one_task(): void
    {
        $first  = $this->generator()->once($this->org->id, 'test', 'abc', 'recurrence', ['title' => 'Once']);
        $second = $this->generator()->once($this->org->id, 'test', 'abc', 'recurrence', ['title' => 'Once']);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Task::count());
    }

    /**
     * A deleted generated task must not come back.
     *
     * Otherwise deleting one is a game nobody wins — it reappears on the next
     * run, and the second delete is the point at which people stop trusting
     * the list.
     */
    public function test_a_deleted_generated_task_is_not_raised_again(): void
    {
        $task = $this->generator()->once($this->org->id, 'test', 'abc', 'recurrence', ['title' => 'Once']);
        $task->delete();

        $this->generator()->once($this->org->id, 'test', 'abc', 'recurrence', ['title' => 'Once']);

        $this->assertSame(0, Task::count());
        $this->assertSame(1, Task::withTrashed()->count());
    }

    /** Two tenants generating from the same key must not collide. */
    public function test_the_key_is_scoped_to_the_organisation(): void
    {
        $other = Organization::create(['name' => 'Beta', 'slug' => 'beta']);

        $this->generator()->once($this->org->id, 'test', 'abc', 'recurrence', ['title' => 'Ours']);
        $this->generator()->once($other->id, 'test', 'abc', 'recurrence', ['title' => 'Theirs']);

        $this->assertSame(2, Task::withoutGlobalScopes()->count());
    }

    /** A generated task says so, so nobody mistakes it for one they wrote. */
    public function test_a_generated_task_is_marked_as_generated(): void
    {
        $this->coordinator();

        $task = $this->generator()->once($this->org->id, 'test', 'abc', 'recurrence', ['title' => 'Raised']);

        $this->getJson($this->url('/' . $task->id))
            ->assertOk()
            ->assertJsonPath('data.source', 'recurrence')
            ->assertJsonPath('data.is_generated', true);
    }

    public function test_a_typed_task_is_not_marked_as_generated(): void
    {
        $this->coordinator();

        $this->postJson($this->url(), ['title' => 'I typed this'])
            ->assertCreated()
            ->assertJsonPath('data.source', 'manual')
            ->assertJsonPath('data.is_generated', false);
    }

    // ── Recurrence ────────────────────────────────────────────────────────

    /**
     * A schedule nobody ran for three months owes three tasks, not one.
     *
     * Quietly raising only the latest is how a monthly compliance check becomes
     * a quarterly one without anybody deciding to change it.
     */
    public function test_a_schedule_that_was_not_run_owes_every_missed_occurrence(): void
    {
        $schedule = $this->schedule([
            'starts_on' => now()->subMonths(3)->startOfMonth()->toDateString(),
        ]);

        $made = $this->generator()->runRecurrence($schedule);

        $this->assertCount(4, $made, 'Three months back, plus this one.');
        $this->assertSame(4, Task::count());
    }

    public function test_running_a_schedule_twice_raises_nothing_the_second_time(): void
    {
        $schedule = $this->schedule();

        $this->generator()->runRecurrence($schedule);
        $before = Task::count();

        $this->generator()->runRecurrence($schedule->fresh());

        $this->assertSame($before, Task::count());
    }

    /**
     * The deadline is relative to the occurrence — "within a week of the first
     * of the month", not "the eighth of January forever".
     */
    public function test_the_deadline_follows_each_occurrence(): void
    {
        $start = CarbonImmutable::parse(now()->subMonth()->startOfMonth()->toDateString());

        $schedule = $this->schedule(['starts_on' => $start->toDateString(), 'lead_days' => 7]);

        $made = $this->generator()->runRecurrence($schedule);

        $this->assertSame(
            $start->addDays(7)->toDateString(),
            $made[0]->deadline->toDateString(),
        );
        $this->assertSame(
            $start->addMonthNoOverflow()->addDays(7)->toDateString(),
            $made[1]->deadline->toDateString(),
        );
    }

    /** A finished schedule stops rather than running on forever. */
    public function test_a_schedule_stops_at_its_end_date(): void
    {
        $schedule = $this->schedule([
            'starts_on' => now()->subMonths(4)->startOfMonth()->toDateString(),
            'ends_on'   => now()->subMonths(2)->endOfMonth()->toDateString(),
        ]);

        $made = $this->generator()->runRecurrence($schedule);

        $this->assertCount(3, $made);
    }

    public function test_an_inactive_schedule_raises_nothing(): void
    {
        $schedule = $this->schedule(['is_active' => false]);

        $this->assertSame([], $this->generator()->runRecurrence($schedule));
    }

    /**
     * A recurrence starting years back is a typo, not an intention. Refusing it
     * is kinder than raising two dozen tasks and letting somebody delete them
     * one at a time.
     */
    public function test_a_schedule_cannot_start_years_in_the_past(): void
    {
        $this->coordinator();

        $this->postJson($this->url('/recurrences'), [
            'title' => 'Monthly review', 'frequency' => 'monthly',
            'starts_on' => now()->subYears(3)->toDateString(),
        ])->assertJsonValidationErrorFor('starts_on');
    }

    // ── Through the API ───────────────────────────────────────────────────

    public function test_a_schedule_can_be_created_and_run_on_demand(): void
    {
        $this->coordinator();

        $created = $this->postJson($this->url('/recurrences'), [
            'title' => 'Quarterly access review', 'frequency' => 'quarterly',
            'starts_on' => now()->startOfMonth()->toDateString(), 'lead_days' => 14,
        ])->assertCreated()->json('data');

        $this->postJson($this->url("/recurrences/{$created['id']}/run"))
            ->assertOk()
            ->assertJsonPath('created', 1);

        $this->assertSame('Quarterly access review', Task::first()->title);
        $this->assertSame('recurrence', Task::first()->source);
    }

    /** Safe to press twice, because every task is keyed to its occurrence. */
    public function test_running_a_schedule_from_the_api_twice_is_safe(): void
    {
        $this->coordinator();

        $created = $this->postJson($this->url('/recurrences'), [
            'title' => 'Monthly review', 'frequency' => 'monthly',
            'starts_on' => now()->startOfMonth()->toDateString(),
        ])->assertCreated()->json('data');

        $this->postJson($this->url("/recurrences/{$created['id']}/run"))->assertOk();
        $this->postJson($this->url("/recurrences/{$created['id']}/run"))
            ->assertOk()->assertJsonPath('created', 0);

        $this->assertSame(1, Task::count());
    }

    /**
     * Stopped rather than deleted, so the tasks it already raised stay
     * explicable rather than becoming orphans nobody can account for.
     */
    public function test_removing_a_schedule_stops_it_without_deleting_it(): void
    {
        $this->coordinator();

        $created = $this->postJson($this->url('/recurrences'), [
            'title' => 'Monthly review', 'frequency' => 'monthly',
            'starts_on' => now()->startOfMonth()->toDateString(),
        ])->assertCreated()->json('data');

        $this->deleteJson($this->url("/recurrences/{$created['id']}"))
            ->assertOk()->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('task_recurrences', ['id' => $created['id']]);
    }

    /** So a schedule can be checked without waiting a month to find it wrong. */
    public function test_a_schedule_reports_what_it_will_raise_next(): void
    {
        $this->coordinator();

        $this->postJson($this->url('/recurrences'), [
            'title' => 'Monthly review', 'frequency' => 'monthly',
            'starts_on' => now()->addMonth()->startOfMonth()->toDateString(),
        ])->assertCreated()
            ->assertJsonPath('data.next_due', now()->addMonth()->startOfMonth()->toDateString());
    }

    public function test_generated_tasks_can_be_filtered_out_of_the_list(): void
    {
        $this->coordinator();

        $this->postJson($this->url(), ['title' => 'I typed this'])->assertCreated();
        $this->generator()->once($this->org->id, 'test', 'abc', 'recurrence', ['title' => 'Raised']);

        $this->getJson($this->url() . '&source=manual')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'I typed this');
    }
}
