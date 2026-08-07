<?php

namespace Tests\Feature\Task;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Task\Task;
use App\Models\Task\TaskHandover;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covering somebody's work while they are away — and what cannot be covered.
 *
 * The legacy's handover register ran to 4,361 rows across 548 leave requests
 * and asked for a name against every open task. Its notes are mostly the word
 * "Test", which is what a form looks like once people stop reading it — and
 * they stopped because most of what it asked made no sense. Nobody can sit your
 * certification exam or take over your research thread for a fortnight.
 *
 * So the classification is the feature, and it is what is proved here.
 */
class LeaveHandoverTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private LeaveType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);

        $this->type = LeaveType::create([
            'organization_id' => $this->org->id,
            'name' => 'Annual', 'code' => 'ANN', 'days_per_year' => 21,
        ]);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function employee(string $first = 'Test', bool $withLogin = false, array $permissions = []): Employee
    {
        $employee = Employee::create([
            'employee_number'         => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id,
            'first_name' => $first, 'last_name' => 'Person',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
        ]);

        if ($withLogin) {
            $user = User::factory()->create();
            $role = Role::create([
                'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
                'display_name' => 'Test', 'scope' => 'organization',
                'permissions' => $permissions ?: [Permission::TASKS_VIEW_OWN, Permission::TASKS_UPDATE],
            ]);
            $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
            $user->forgetCachedRoles();
            $employee->update(['user_id' => $user->id]);
        }

        return $employee->fresh();
    }

    private function leave(Employee $employee, array $attrs = []): LeaveRequest
    {
        return LeaveRequest::create($attrs + [
            'organization_id' => $this->org->id,
            'employee_id'     => $employee->id,
            'leave_type_id'   => $this->type->id,
            'from_date'       => now()->addWeek()->toDateString(),
            'to_date'         => now()->addWeeks(3)->toDateString(),
            'days_requested'  => 10,
            'status'          => LeaveRequest::STATUS_APPROVED,
        ]);
    }

    private function task(Employee $owner, array $attrs = []): Task
    {
        return Task::create($attrs + [
            'organization_id' => $this->org->id,
            'assigned_to'     => $owner->id,
            'title'           => 'Chase the Westlands site survey',
        ]);
    }

    private function url(LeaveRequest $leave, string $path = ''): string
    {
        return "/api/v1/leave/{$leave->id}/handover{$path}?org={$this->org->slug}";
    }

    // ── What can and cannot be covered ────────────────────────────────────

    /**
     * The customer-facing and time-critical work, where the cost of waiting a
     * fortnight lands on somebody outside the company.
     */
    public function test_customer_facing_work_can_be_covered(): void
    {
        $away = $this->employee('Away', withLogin: true);
        Sanctum::actingAs($away->user);

        foreach (['support', 'ticket', 'account', 'opportunity'] as $category) {
            $this->task($away, ['category' => $category, 'title' => ucfirst($category)]);
        }

        $this->getJson($this->url($this->leave($away)))
            ->assertOk()
            ->assertJsonCount(4, 'coverable')
            ->assertJsonPath('summary.coverable', 4);
    }

    /**
     * Nobody can sit your exam, inherit your reading, or have been in a meeting
     * they were not in. Project work is the project manager's call about
     * resourcing, not something to settle in a leave form.
     */
    public function test_personal_and_project_work_cannot_be_covered(): void
    {
        $away = $this->employee('Away', withLogin: true);
        Sanctum::actingAs($away->user);

        foreach (['training', 'certificate', 'research', 'meeting', 'project'] as $category) {
            $this->task($away, ['category' => $category, 'title' => ucfirst($category)]);
        }

        $response = $this->getJson($this->url($this->leave($away)))->assertOk();

        $this->assertCount(0, $response->json('coverable'));
        $this->assertSame(5, count($response->json('personal')) + count($response->json('at_risk')));
    }

    /**
     * Said out loud. A rule people cannot see reads as obstruction, and they
     * route around it by handing the task over informally — which is the thing
     * this exists to stop.
     */
    public function test_each_blocked_task_says_why(): void
    {
        $away = $this->employee('Away', withLogin: true);
        Sanctum::actingAs($away->user);

        $this->task($away, ['category' => 'research', 'deadline' => now()->addMonths(3)->toDateString()]);

        $this->getJson($this->url($this->leave($away)))
            ->assertOk()
            ->assertJsonPath('personal.0.is_transferable', false)
            ->assertJsonPath(
                'personal.0.block_reason',
                'Research is a thread; handing it over halfway means it gets redone.',
            );
    }

    /**
     * The category is a good guess, not a law. A self-raised task might be
     * personal admin or the one thing holding up a delivery, and only the
     * person going away knows which.
     */
    public function test_a_task_can_overrule_the_rule_for_its_kind_of_work(): void
    {
        $away = $this->employee('Away', withLogin: true);
        Sanctum::actingAs($away->user);

        // Ordinarily coverable, but this one is theirs.
        $this->task($away, ['category' => 'internal', 'handover_policy' => 'personal', 'title' => 'Mine']);
        // Ordinarily not, but this one has to be held.
        $this->task($away, ['category' => 'project', 'handover_policy' => 'transferable', 'title' => 'Cover it']);

        $response = $this->getJson($this->url($this->leave($away)))->assertOk();

        $this->assertSame(['Cover it'], array_column($response->json('coverable'), 'title'));
        $this->assertSame('Marked as yours alone.', $response->json('personal.0.block_reason'));
    }

    // ── What needs deciding before they go ────────────────────────────────

    /**
     * The question the legacy could never ask, because it could not tell the
     * two apart. A personal task due while its owner is away will be late
     * unless it is moved, finished early, or knowingly allowed to slip.
     */
    public function test_uncoverable_work_falling_due_during_the_leave_is_flagged(): void
    {
        $away = $this->employee('Away', withLogin: true);
        Sanctum::actingAs($away->user);

        $leave = $this->leave($away);

        $this->task($away, [
            'category' => 'research', 'title' => 'Due while away',
            'deadline' => $leave->to_date->copy()->subDays(2)->toDateString(),
        ]);
        $this->task($away, [
            'category' => 'research', 'title' => 'Due after they are back',
            'deadline' => $leave->to_date->copy()->addMonth()->toDateString(),
        ]);

        $this->getJson($this->url($leave))
            ->assertOk()
            ->assertJsonPath('summary.at_risk', 1)
            ->assertJsonPath('at_risk.0.title', 'Due while away')
            ->assertJsonPath('personal.0.title', 'Due after they are back');
    }

    /** Work with no deadline is waiting for them, not at risk. */
    public function test_uncoverable_work_with_no_deadline_is_not_at_risk(): void
    {
        $away = $this->employee('Away', withLogin: true);
        Sanctum::actingAs($away->user);

        $this->task($away, ['category' => 'training', 'title' => 'No deadline']);

        $this->getJson($this->url($this->leave($away)))
            ->assertOk()
            ->assertJsonPath('summary.at_risk', 0)
            ->assertJsonPath('summary.personal', 1);
    }

    // ── Handing over ──────────────────────────────────────────────────────

    public function test_a_coverable_task_can_be_handed_to_somebody(): void
    {
        $away    = $this->employee('Away', withLogin: true);
        $coverer = $this->employee('Coverer');
        Sanctum::actingAs($away->user);

        $leave = $this->leave($away);
        $task  = $this->task($away, ['category' => 'support']);

        $this->postJson($this->url($leave), [
            'task_id' => $task->id, 'to_employee_id' => $coverer->id,
            'note' => 'The client calls on Tuesdays.',
        ])->assertOk()
            ->assertJsonPath('coverable.0.covered_by.name', 'Coverer Person')
            ->assertJsonPath('summary.covered', 1);
    }

    /**
     * The classification is only worth having if it holds. Somebody who
     * disagrees changes the task's own policy — a deliberate act on the task,
     * not a shortcut buried in a leave form.
     */
    public function test_work_that_cannot_be_covered_is_refused(): void
    {
        $away    = $this->employee('Away', withLogin: true);
        $coverer = $this->employee('Coverer');
        Sanctum::actingAs($away->user);

        $leave = $this->leave($away);
        $task  = $this->task($away, ['category' => 'training']);

        $this->postJson($this->url($leave), [
            'task_id' => $task->id, 'to_employee_id' => $coverer->id,
        ])->assertStatus(422);

        $this->assertSame(0, TaskHandover::count());
    }

    public function test_a_task_cannot_be_handed_to_the_person_going_away(): void
    {
        $away = $this->employee('Away', withLogin: true);
        Sanctum::actingAs($away->user);

        $leave = $this->leave($away);
        $task  = $this->task($away, ['category' => 'support']);

        $this->postJson($this->url($leave), [
            'task_id' => $task->id, 'to_employee_id' => $away->id,
        ])->assertStatus(422);
    }

    /**
     * Naming somebody else replaces the first rather than leaving two people
     * each assuming the other has it.
     */
    public function test_naming_a_second_coverer_replaces_the_first(): void
    {
        $away  = $this->employee('Away', withLogin: true);
        $first = $this->employee('First');
        $second = $this->employee('Second');
        Sanctum::actingAs($away->user);

        $leave = $this->leave($away);
        $task  = $this->task($away, ['category' => 'support']);

        $this->postJson($this->url($leave), ['task_id' => $task->id, 'to_employee_id' => $first->id])->assertOk();
        $this->postJson($this->url($leave), ['task_id' => $task->id, 'to_employee_id' => $second->id])
            ->assertOk()
            ->assertJsonPath('coverable.0.covered_by.name', 'Second Person');

        $this->assertSame(1, TaskHandover::count());
    }

    /**
     * Ownership never moves. Overwriting it would lose who the task goes back
     * to, and after two consecutive leaves nobody could say whose it was.
     */
    public function test_cover_does_not_change_who_owns_the_task(): void
    {
        $away    = $this->employee('Away', withLogin: true);
        $coverer = $this->employee('Coverer');
        Sanctum::actingAs($away->user);

        $task = $this->task($away, ['category' => 'support']);

        $this->postJson($this->url($this->leave($away)), [
            'task_id' => $task->id, 'to_employee_id' => $coverer->id,
        ])->assertOk();

        $this->assertSame($away->id, $task->fresh()->assigned_to);
    }

    /** Cover that cannot be opened is not cover. */
    public function test_the_coverer_can_see_what_they_are_holding(): void
    {
        $away    = $this->employee('Away', withLogin: true);
        $coverer = $this->employee('Coverer', withLogin: true);
        Sanctum::actingAs($away->user);

        $task = $this->task($away, ['category' => 'support']);

        $this->postJson($this->url($this->leave($away)), [
            'task_id' => $task->id, 'to_employee_id' => $coverer->id,
        ])->assertOk();

        Sanctum::actingAs($coverer->user);

        $this->getJson("/api/v1/tasks?org={$this->org->slug}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.covered_by.name', 'Coverer Person');
    }

    // ── Handing back ──────────────────────────────────────────────────────

    /**
     * Yesterday or earlier: somebody back today held the task through
     * yesterday, and ending cover a day early is how a Monday starts with an
     * unowned support case.
     */
    public function test_cover_ends_once_the_leave_is_over(): void
    {
        $away    = $this->employee('Away');
        $coverer = $this->employee('Coverer');

        $leave = $this->leave($away, [
            'from_date' => now()->subWeeks(2)->toDateString(),
            'to_date'   => now()->subDay()->toDateString(),
        ]);

        TaskHandover::create([
            'organization_id' => $this->org->id,
            'task_id' => $this->task($away, ['category' => 'support'])->id,
            'leave_request_id' => $leave->id,
            'to_employee_id' => $coverer->id,
            'starts_on' => $leave->from_date, 'ends_on' => $leave->to_date,
        ]);

        $this->artisan('tasks:release-handovers')->assertSuccessful();

        $this->assertNotNull(TaskHandover::first()->released_at);
    }

    public function test_cover_still_standing_is_left_alone(): void
    {
        $away    = $this->employee('Away');
        $coverer = $this->employee('Coverer');
        $leave   = $this->leave($away);

        TaskHandover::create([
            'organization_id' => $this->org->id,
            'task_id' => $this->task($away, ['category' => 'support'])->id,
            'leave_request_id' => $leave->id,
            'to_employee_id' => $coverer->id,
            'starts_on' => $leave->from_date, 'ends_on' => $leave->to_date,
        ]);

        $this->artisan('tasks:release-handovers')->assertSuccessful();

        $this->assertNull(TaskHandover::first()->released_at);
    }

    public function test_cover_can_be_taken_back_early(): void
    {
        $away    = $this->employee('Away', withLogin: true);
        $coverer = $this->employee('Coverer');
        Sanctum::actingAs($away->user);

        $leave = $this->leave($away);
        $task  = $this->task($away, ['category' => 'support']);

        $this->postJson($this->url($leave), ['task_id' => $task->id, 'to_employee_id' => $coverer->id])->assertOk();
        $handover = TaskHandover::firstOrFail();

        $this->deleteJson($this->url($leave, "/{$handover->id}"))
            ->assertOk()
            ->assertJsonPath('coverable.0.covered_by', null);
    }

    // ── Who may see it ────────────────────────────────────────────────────

    /** Somebody else's handover is none of their business. */
    public function test_another_employee_cannot_open_somebody_elses_handover(): void
    {
        $away      = $this->employee('Away');
        $bystander = $this->employee('Bystander', withLogin: true);

        Sanctum::actingAs($bystander->user);

        $this->getJson($this->url($this->leave($away)))->assertForbidden();
    }

    /**
     * Somebody has to be able to pick up the pieces when a person goes away
     * without arranging cover.
     */
    public function test_a_task_administrator_can_open_anybody_s_handover(): void
    {
        $away  = $this->employee('Away');
        $admin = $this->employee('Admin', withLogin: true, permissions: [Permission::TASKS_VIEW]);

        Sanctum::actingAs($admin->user);

        $this->getJson($this->url($this->leave($away)))->assertOk();
    }
}
