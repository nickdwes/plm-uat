<?php

namespace Tests\Feature\Task;

use App\Models\Employee;
use App\Models\Notification;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Task\Task;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Signing a task off, and telling people.
 *
 * Three holes in the same place — what actually happened to a task.
 *
 * Approval had three columns, a status and a column on the board, and nothing
 * that could act on any of it. 24% of the legacy's 9,055 tasks were approved,
 * so it was a live path left half-built.
 *
 * `started_by` was filled on 96% of legacy tasks and `completed_by` on 91%. I
 * recorded who approved and not who did the work.
 *
 * And nobody was ever told anything. The legacy sent 14,427 notifications; an
 * inbox alone only works for people who think to open it.
 */
class TaskApprovalTest extends TestCase
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
        $this->grant($user, $permissions);

        $employee = $this->employee();
        $employee->update(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        return $employee->fresh();
    }

    private function grant(User $user, array $permissions): void
    {
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Test', 'scope' => 'organization', 'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
    }

    /** An employee who can log in — the only kind that can be notified. */
    private function employee(string $first = 'Test', bool $withLogin = false): Employee
    {
        $employee = Employee::create([
            'employee_number'         => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id,
            'first_name' => $first, 'last_name' => 'Person',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
        ]);

        if ($withLogin) {
            $user = User::factory()->create();
            $this->grant($user, [Permission::TASKS_VIEW_OWN, Permission::TASKS_UPDATE]);
            $employee->update(['user_id' => $user->id]);
        }

        return $employee->fresh();
    }

    private function coordinator(): Employee
    {
        return $this->actor([
            Permission::TASKS_VIEW, Permission::TASKS_CREATE,
            Permission::TASKS_UPDATE, Permission::TASKS_DELETE,
        ]);
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

    // ── Who did the work ──────────────────────────────────────────────────

    /**
     * Stamped the first time the task leaves todo, because that is the moment
     * it happened — not whenever somebody later types a start date.
     */
    public function test_picking_a_task_up_records_who_started_it(): void
    {
        $me   = $this->coordinator();
        $task = $this->task();

        $this->putJson($this->url('/' . $task->id), [
            'title' => $task->title, 'status' => 'in_progress',
        ])->assertOk();

        $this->assertSame($me->id, $task->fresh()->started_by);
        $this->assertNotNull($task->fresh()->started_on);
    }

    /** The owner and whoever actually finished it are different questions. */
    public function test_finishing_a_task_records_who_finished_it(): void
    {
        $me    = $this->coordinator();
        $owner = $this->employee('Owner');
        $task  = $this->task(['assigned_to' => $owner->id]);

        $this->putJson($this->url('/' . $task->id), [
            'title' => $task->title, 'status' => 'completed',
        ])->assertOk();

        $this->assertSame($owner->id, $task->fresh()->assigned_to);
        $this->assertSame($me->id, $task->fresh()->completed_by, 'Whoever finished it, not whoever owns it.');
    }

    /** Reopening clears it, so a finished record never outlives the state. */
    public function test_reopening_clears_who_finished_it(): void
    {
        $this->coordinator();
        $task = $this->task();

        $this->putJson($this->url('/' . $task->id), ['title' => $task->title, 'status' => 'completed'])->assertOk();
        $this->putJson($this->url('/' . $task->id), ['title' => $task->title, 'status' => 'in_progress'])->assertOk();

        $this->assertNull($task->fresh()->completed_by);
        $this->assertNull($task->fresh()->completed_at);
        // But who started it stands — reopening does not unhappen the pickup.
        $this->assertNotNull($task->fresh()->started_by);
    }

    // ── Approval ──────────────────────────────────────────────────────────

    /**
     * Otherwise `requires_approval` is a note rather than a rule, and the
     * Awaiting Approval column only holds what somebody remembered to put
     * there.
     */
    public function test_a_task_needing_sign_off_cannot_be_finished_by_finishing_it(): void
    {
        $this->coordinator();
        $approver = $this->employee('Approver', withLogin: true);

        $task = $this->task(['requires_approval' => true, 'approver_id' => $approver->id]);

        $this->putJson($this->url('/' . $task->id), [
            'title' => $task->title, 'status' => 'completed',
        ])->assertOk()->assertJsonPath('data.status', 'awaiting_approval');

        $this->assertNull($task->fresh()->completed_at);
    }

    public function test_the_named_approver_signs_it_off(): void
    {
        $this->coordinator();
        $approver = $this->employee('Approver', withLogin: true);
        $owner    = $this->employee('Owner');

        $task = $this->task([
            'requires_approval' => true, 'approver_id' => $approver->id,
            'assigned_to' => $owner->id, 'status' => 'awaiting_approval',
        ]);

        Sanctum::actingAs($approver->user);

        $this->postJson($this->url("/{$task->id}/approval"), ['approved' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->assertNotNull($task->fresh()->approved_at);
        // Credited to whoever owned the work, not to the person signing it.
        $this->assertSame($owner->id, $task->fresh()->completed_by);
    }

    /**
     * Sent back to in progress, not to todo. The work was done and found
     * wanting; putting it at the start would say nobody had touched it.
     */
    public function test_sending_a_task_back_returns_it_to_in_progress_with_the_reason(): void
    {
        $this->coordinator();
        $approver = $this->employee('Approver', withLogin: true);

        $task = $this->task([
            'requires_approval' => true, 'approver_id' => $approver->id,
            'status' => 'awaiting_approval',
        ]);

        Sanctum::actingAs($approver->user);

        $this->postJson($this->url("/{$task->id}/approval"), [
            'approved' => false, 'note' => 'The configuration backup is missing.',
        ])->assertOk()->assertJsonPath('data.status', 'in_progress');

        $this->assertNull($task->fresh()->approved_at);
        $this->assertSame('The configuration backup is missing.', $task->fresh()->approval_note);
    }

    /**
     * `requires_approval` alone said a signature was needed without saying
     * whose, which leaves the task waiting on nobody in particular.
     */
    public function test_only_the_named_approver_can_decide(): void
    {
        $this->coordinator();
        $approver = $this->employee('Approver', withLogin: true);

        $task = $this->task([
            'requires_approval' => true, 'approver_id' => $approver->id,
            'status' => 'awaiting_approval',
        ]);

        // Still acting as the coordinator, who is not the approver.
        $this->postJson($this->url("/{$task->id}/approval"), ['approved' => true])
            ->assertForbidden();
    }

    public function test_a_task_with_no_named_approver_cannot_be_approved_by_anyone(): void
    {
        $this->coordinator();
        $task = $this->task(['requires_approval' => true, 'status' => 'awaiting_approval']);

        $this->postJson($this->url("/{$task->id}/approval"), ['approved' => true])
            ->assertForbidden();
    }

    /**
     * The client is told whether it may decide, rather than comparing ids
     * itself — showing an Approve button the server will refuse is worse than
     * showing none.
     */
    public function test_only_the_approver_is_told_they_can_decide(): void
    {
        $this->coordinator();
        $approver = $this->employee('Approver', withLogin: true);

        $task = $this->task([
            'requires_approval' => true, 'approver_id' => $approver->id,
            'status' => 'awaiting_approval',
        ]);

        $this->getJson($this->url('/' . $task->id))->assertOk()->assertJsonPath('data.can_approve', false);

        Sanctum::actingAs($approver->user);
        $this->getJson($this->url('/' . $task->id))->assertOk()->assertJsonPath('data.can_approve', true);
    }

    // ── Being told ────────────────────────────────────────────────────────

    /** Being asked is the whole mechanism, so it is worth interrupting for. */
    public function test_being_asked_to_help_notifies_the_person_asked(): void
    {
        $this->coordinator();
        $helper = $this->employee('Helper', withLogin: true);
        $task   = $this->task();

        $this->postJson($this->url("/{$task->id}/requests"), [
            'kind' => 'delegate', 'to_employee_id' => $helper->id,
        ])->assertOk();

        $notification = Notification::where('user_id', $helper->user_id)->firstOrFail();
        $this->assertSame('task.requested', $notification->type);
        $this->assertSame($task->id, $notification->data['task_id']);
    }

    /** From the asker's side, silence and a decline look identical. */
    public function test_answering_a_request_notifies_whoever_asked(): void
    {
        $asker  = $this->coordinator();
        $helper = $this->employee('Helper', withLogin: true);
        $task   = $this->task();

        $this->postJson($this->url("/{$task->id}/requests"), [
            'kind' => 'assist', 'to_employee_id' => $helper->id,
        ])->assertOk();

        $requestId = $task->requests()->value('id');
        Sanctum::actingAs($helper->user);

        $this->postJson($this->url("/{$task->id}/requests/{$requestId}/respond"), ['status' => 'declined'])
            ->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $asker->user_id, 'type' => 'task.request_answered',
        ]);
    }

    public function test_escalating_an_issue_notifies_the_person_it_went_to(): void
    {
        $this->coordinator();
        $manager = $this->employee('Grace', withLogin: true);
        $task    = $this->task();

        $this->postJson($this->url("/{$task->id}/issues"), [
            'name' => 'The client has not signed off the design',
            'escalate_to' => $manager->id,
        ])->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $manager->user_id, 'type' => 'task.issue_escalated',
        ]);
    }

    public function test_sending_a_task_for_approval_notifies_the_approver(): void
    {
        $this->coordinator();
        $approver = $this->employee('Approver', withLogin: true);

        $task = $this->task(['requires_approval' => true, 'approver_id' => $approver->id]);

        $this->putJson($this->url('/' . $task->id), [
            'title' => $task->title, 'status' => 'completed',
        ])->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $approver->user_id, 'type' => 'task.awaiting_approval',
        ]);
    }

    /**
     * An employee with no login cannot be notified, and that must not take
     * down the action that triggered it — somebody should not be un-askable
     * merely because they have no account yet.
     */
    public function test_asking_somebody_without_a_login_still_works(): void
    {
        $this->coordinator();
        $noLogin = $this->employee('Contractor');
        $task    = $this->task();

        $this->postJson($this->url("/{$task->id}/requests"), [
            'kind' => 'assist', 'to_employee_id' => $noLogin->id,
        ])->assertOk();

        $this->assertSame(1, $task->requests()->count());
        $this->assertSame(0, Notification::count());
    }

    /**
     * Deliberately quiet: creating and editing tasks send nothing. Those are
     * the notifications people learn to ignore, and once they do the ones that
     * matter stop working too.
     */
    public function test_ordinary_edits_notify_nobody(): void
    {
        $this->coordinator();
        $task = $this->task();

        $this->postJson($this->url(), ['title' => 'Another task'])->assertCreated();
        $this->putJson($this->url('/' . $task->id), ['title' => 'Renamed'])->assertOk();

        $this->assertSame(0, Notification::count());
    }
}
