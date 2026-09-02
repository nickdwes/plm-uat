<?php

namespace Tests\Feature\Task;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\Project\ProjectComment;
use App\Models\Role;
use App\Models\Task\Task;
use App\Models\Task\TaskMember;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What the reader is to a task, and what the section list needs to draw itself.
 *
 * Every other field on the payload is the same for everyone who asks; these two
 * are the first that depend on who is reading.
 */
class TaskInvolvementTest extends TestCase
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

    private function actor(array $permissions): Employee
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Test', 'scope' => 'organization', 'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();

        $employee = $this->employee();
        $employee->update(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        return $employee->fresh();
    }

    private function employee(string $first = 'Test'): Employee
    {
        return Employee::create([
            'employee_number'         => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id,
            'first_name' => $first, 'last_name' => 'Person',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
        ]);
    }

    private function coordinator(): Employee
    {
        return $this->actor([
            Permission::TASKS_VIEW, Permission::TASKS_CREATE,
            Permission::TASKS_UPDATE, Permission::TASKS_DELETE,
        ]);
    }

    /** Someone who can read every task but change none of them. */
    private function onlooker(): Employee
    {
        return $this->actor([Permission::TASKS_VIEW]);
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/tasks{$path}?org={$this->org->slug}";
    }

    private function task(array $attrs = []): Task
    {
        return Task::create($attrs + [
            'organization_id' => $this->org->id,
            'title'           => 'Renew the Fortinet support contract',
        ]);
    }

    // ── Involvement ───────────────────────────────────────────────────────

    public function test_a_task_nobody_joined_reads_as_no_involvement(): void
    {
        $this->coordinator();
        $task = $this->task();

        $this->getJson($this->url("/{$task->id}"))
            ->assertOk()
            ->assertJsonPath('data.my_involvement', null);
    }

    public function test_the_assignee_is_doing_it_without_a_member_row(): void
    {
        $me = $this->coordinator();
        $task = $this->task(['assigned_to' => $me->id]);

        $this->assertSame(0, TaskMember::where('task_id', $task->id)->count());
        $this->getJson($this->url("/{$task->id}"))
            ->assertOk()
            ->assertJsonPath('data.my_involvement', 'doing');
    }

    public function test_each_involvement_can_be_set_and_read_back(): void
    {
        $this->coordinator();
        $task = $this->task();

        foreach (['doing', 'assisting', 'tracking'] as $what) {
            $this->putJson($this->url("/{$task->id}/involvement"), ['involvement' => $what])
                ->assertOk()
                ->assertJsonPath('data.my_involvement', $what);
        }
    }

    public function test_involvement_is_exclusive(): void
    {
        $me = $this->coordinator();
        $task = $this->task();

        $this->putJson($this->url("/{$task->id}/involvement"), ['involvement' => 'assisting'])->assertOk();
        $this->putJson($this->url("/{$task->id}/involvement"), ['involvement' => 'tracking'])->assertOk();

        // Not "assisting and also tracking" — one row, the latest answer.
        $rows = TaskMember::where('task_id', $task->id)->where('employee_id', $me->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('tracking', $rows->first()->capacity);
    }

    public function test_stepping_back_clears_it_and_releases_the_task(): void
    {
        $me = $this->coordinator();
        $task = $this->task(['assigned_to' => $me->id]);

        $this->putJson($this->url("/{$task->id}/involvement"), ['involvement' => null])
            ->assertOk()
            ->assertJsonPath('data.my_involvement', null);

        // The accountable person is named on the task, so letting go has to
        // release that too or it still reads as theirs.
        $this->assertNull($task->fresh()->assigned_to);
        $this->assertSame(0, TaskMember::where('task_id', $task->id)->where('employee_id', $me->id)->count());
    }

    public function test_taking_a_task_on_needs_the_right_to_change_it(): void
    {
        $this->onlooker();
        $task = $this->task();

        // Helping and watching are open to anyone who can see the task…
        $this->putJson($this->url("/{$task->id}/involvement"), ['involvement' => 'assisting'])->assertOk();
        $this->putJson($this->url("/{$task->id}/involvement"), ['involvement' => 'tracking'])->assertOk();

        // …but declaring yourself as doing it is self-assignment.
        $this->putJson($this->url("/{$task->id}/involvement"), ['involvement' => 'doing'])->assertForbidden();
    }

    public function test_somebody_already_assigned_may_confirm_they_are_doing_it(): void
    {
        // TASKS_VIEW_OWN + assigned = they can edit their own task, so this is
        // not an escalation — they are already the person doing it.
        $me = $this->actor([Permission::TASKS_VIEW, Permission::TASKS_VIEW_OWN]);
        $task = $this->task(['assigned_to' => $me->id]);

        $this->putJson($this->url("/{$task->id}/involvement"), ['involvement' => 'doing'])
            ->assertOk()
            ->assertJsonPath('data.my_involvement', 'doing');
    }

    public function test_one_persons_involvement_is_not_another_persons(): void
    {
        $mine = $this->coordinator();
        $task = $this->task();
        $this->putJson($this->url("/{$task->id}/involvement"), ['involvement' => 'tracking'])->assertOk();

        // A second reader sees the same task and their own answer.
        $this->coordinator();
        $this->getJson($this->url("/{$task->id}"))
            ->assertOk()
            ->assertJsonPath('data.my_involvement', null);

        $this->assertSame('tracking', TaskMember::where('task_id', $task->id)
            ->where('employee_id', $mine->id)->value('capacity'));
    }

    /**
     * So the panel can grey the control out instead of offering something the
     * server will refuse. Involvement is a row against an employee, so a user
     * without an employee record cannot hold one at all.
     */
    public function test_the_payload_says_who_may_set_involvement(): void
    {
        $this->coordinator();
        $task = $this->task();

        $this->getJson($this->url("/{$task->id}"))
            ->assertOk()
            ->assertJsonPath('data.can_set_involvement', true)
            ->assertJsonPath('data.can_take_task', true);

        // Read-only: may join as a helper or a watcher, may not take it on.
        $this->onlooker();
        $this->getJson($this->url("/{$task->id}"))
            ->assertOk()
            ->assertJsonPath('data.can_set_involvement', true)
            ->assertJsonPath('data.can_take_task', false);
    }

    public function test_a_user_with_no_employee_record_cannot_hold_involvement(): void
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Admin', 'scope' => 'organization',
            'permissions' => [Permission::TASKS_VIEW, Permission::TASKS_CREATE, Permission::TASKS_UPDATE],
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);

        $task = $this->task();

        $this->getJson($this->url("/{$task->id}"))
            ->assertOk()
            ->assertJsonPath('data.my_involvement', null)
            ->assertJsonPath('data.can_set_involvement', false)
            ->assertJsonPath('data.can_take_task', false);

        $this->putJson($this->url("/{$task->id}/involvement"), ['involvement' => 'tracking'])
            ->assertStatus(422);
    }

    public function test_an_existing_assisting_row_reads_as_assisting(): void
    {
        // Written the way accepting an ask writes it, before this endpoint existed.
        $me = $this->coordinator();
        $task = $this->task();
        TaskMember::create([
            'organization_id' => $this->org->id, 'task_id' => $task->id,
            'employee_id' => $me->id, 'capacity' => 'assisting',
        ]);

        $this->getJson($this->url("/{$task->id}"))
            ->assertOk()
            ->assertJsonPath('data.my_involvement', 'assisting');
    }

    // ── Who is on the task ────────────────────────────────────────────────

    /**
     * One person is named on the task and accountable; anyone after that is a
     * member doing the same work. Co-assignees could previously only be added
     * from inside a project, and three quarters of tasks have no project.
     */
    public function test_the_first_person_takes_the_seat_and_the_rest_join_them(): void
    {
        $this->coordinator();
        $task = $this->task();
        $first  = $this->employee('First');
        $second = $this->employee('Second');

        $this->postJson($this->url("/{$task->id}/assignees"), ['employee_id' => $first->id])
            ->assertOk()
            ->assertJsonPath('data.assignee.id', $first->id);
        $this->assertSame($first->id, $task->fresh()->assigned_to);

        $res = $this->postJson($this->url("/{$task->id}/assignees"), ['employee_id' => $second->id])->assertOk();

        // Still the first person's task; the second is on it too.
        $this->assertSame($first->id, $task->fresh()->assigned_to);
        $this->assertCount(2, $res->json('data.assignees'));
        $this->assertSame('assignee', TaskMember::where('task_id', $task->id)
            ->where('employee_id', $second->id)->value('capacity'));
    }

    public function test_adding_the_same_person_twice_changes_nothing(): void
    {
        $this->coordinator();
        $task = $this->task();
        $who = $this->employee('Once');

        $this->postJson($this->url("/{$task->id}/assignees"), ['employee_id' => $who->id])->assertOk();
        $res = $this->postJson($this->url("/{$task->id}/assignees"), ['employee_id' => $who->id])->assertOk();

        $this->assertCount(1, $res->json('data.assignees'));
        $this->assertSame(0, TaskMember::where('task_id', $task->id)->count());
    }

    public function test_somebody_can_be_taken_off_again(): void
    {
        $this->coordinator();
        $task = $this->task();
        $owner = $this->employee('Owner');
        $hand  = $this->employee('Hand');

        $this->postJson($this->url("/{$task->id}/assignees"), ['employee_id' => $owner->id])->assertOk();
        $this->postJson($this->url("/{$task->id}/assignees"), ['employee_id' => $hand->id])->assertOk();

        $this->deleteJson($this->url("/{$task->id}/assignees/{$hand->id}"))
            ->assertOk()
            ->assertJsonCount(1, 'data.assignees');

        // Taking the named person off leaves the task unowned rather than
        // quietly promoting somebody who never agreed to it.
        $this->deleteJson($this->url("/{$task->id}/assignees/{$owner->id}"))
            ->assertOk()
            ->assertJsonCount(0, 'data.assignees');
        $this->assertNull($task->fresh()->assigned_to);
    }

    public function test_only_a_real_colleague_can_be_put_on_a_task(): void
    {
        $this->coordinator();
        $task = $this->task();

        $this->postJson($this->url("/{$task->id}/assignees"), ['employee_id' => 999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors('employee_id');
    }

    // ── Section counts ────────────────────────────────────────────────────

    public function test_the_section_counts_arrive_with_the_task(): void
    {
        $me = $this->coordinator();
        $task = $this->task();

        $this->postJson($this->url("/{$task->id}/subtasks"), ['title' => 'Get the renewal quote'])->assertOk();
        $this->postJson($this->url("/{$task->id}/comments"), ['body' => 'Chased the reseller'])->assertOk();
        $this->postJson($this->url("/{$task->id}/issues"), ['name' => 'Quote is 3 weeks late'])->assertOk();
        $this->postJson($this->url("/{$task->id}/required-documents"), ['name' => 'Signed renewal'])->assertOk();

        $counts = $this->getJson($this->url("/{$task->id}"))->assertOk()->json('data.section_counts');

        $this->assertSame(1, $counts['subtasks']);
        $this->assertSame(1, $counts['comments']);
        $this->assertSame(1, $counts['issues']);
        $this->assertSame(1, $counts['resources']);
        // Nothing has been added to these, and they say so rather than being absent.
        $this->assertSame(0, $counts['dependencies']);
        $this->assertSame(0, $counts['assisting']);
        $this->assertSame(0, $counts['meetings']);
    }

    /**
     * The count the panel could not get without opening the tab — meetings are
     * fetched separately, so an empty/non-empty split on first paint had no way
     * to know about them.
     */
    public function test_the_meeting_count_arrives_without_fetching_the_meetings(): void
    {
        $this->coordinator();
        $task = $this->task();

        \App\Models\Task\TaskMeeting::create([
            'organization_id' => $this->org->id, 'task_id' => $task->id,
            'subject' => 'Renewal call', 'scheduled_at' => now()->addDay(), 'duration_minutes' => 30,
        ]);

        $this->assertSame(1, $this->getJson($this->url("/{$task->id}"))
            ->assertOk()->json('data.section_counts.meetings'));
    }

    /** Counting the sub-tasks' own sections would be a query per sub-task. */
    public function test_only_the_task_being_read_carries_the_reader_fields(): void
    {
        $this->coordinator();
        $task = $this->task();
        $this->postJson($this->url("/{$task->id}/subtasks"), ['title' => 'Get the renewal quote'])->assertOk();

        $data = $this->getJson($this->url("/{$task->id}"))->assertOk()->json('data');

        $this->assertArrayHasKey('section_counts', $data);
        $this->assertArrayNotHasKey('section_counts', $data['subtasks'][0]);
        $this->assertArrayNotHasKey('my_involvement', $data['subtasks'][0]);
    }

    public function test_assisting_counts_the_helpers_and_the_asks_still_waiting(): void
    {
        $this->coordinator();
        $task = $this->task();
        $helper = $this->employee('Helper');
        $asked = $this->employee('Asked');

        TaskMember::create([
            'organization_id' => $this->org->id, 'task_id' => $task->id,
            'employee_id' => $helper->id, 'capacity' => 'assisting',
        ]);
        $this->postJson($this->url("/{$task->id}/requests"), [
            'kind' => 'assist', 'to_employee_id' => $asked->id,
        ])->assertOk();

        $this->assertSame(2, $this->getJson($this->url("/{$task->id}"))
            ->assertOk()->json('data.section_counts.assisting'));
    }

    /** Somebody only watching must not appear in the avatar stack. */
    public function test_tracking_does_not_put_you_on_the_task(): void
    {
        $this->coordinator();
        $task = $this->task();

        $data = $this->putJson($this->url("/{$task->id}/involvement"), ['involvement' => 'tracking'])
            ->assertOk()->json('data');

        $this->assertCount(0, $data['assignees']);
        $this->assertCount(0, $data['assisting']);
        $this->assertSame('tracking', $data['my_involvement']);
    }
}
