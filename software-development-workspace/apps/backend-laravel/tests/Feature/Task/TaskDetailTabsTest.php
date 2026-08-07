<?php

namespace Tests\Feature\Task;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Task\Task;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The task detail's tabs, as the designs lay them out.
 *
 * Sub-tasks, Dependencies and Assisting are covered elsewhere. What is proved
 * here is the three the designs added and the legacy had all along: Comments
 * (64,578 rows), Issues (242, of which 212 were escalated) and the counts that
 * sit above the board.
 */
class TaskDetailTabsTest extends TestCase
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

    // ── The counts above the board ────────────────────────────────────────

    /**
     * Derived, never stored. A kept count is one that drifts the first time a
     * status changes by a route that forgot to decrement it.
     */
    public function test_the_summary_counts_each_column_and_what_is_late(): void
    {
        $this->coordinator();

        $this->task(['status' => 'todo']);
        $this->task(['status' => 'todo']);
        $this->task(['status' => 'in_progress']);
        $this->task(['status' => 'awaiting_approval']);
        $this->task(['status' => Task::DONE, 'completed_at' => now()]);
        $this->task(['status' => 'todo', 'deadline' => now()->subWeek()->toDateString()]);

        $this->getJson($this->url('/summary'))
            ->assertOk()
            ->assertJson([
                'total' => 6, 'todo' => 3, 'in_progress' => 1,
                'awaiting' => 1, 'completed' => 1, 'overdue' => 1,
            ]);
    }

    /** A finished task past its deadline is late history, not a live problem. */
    public function test_a_completed_task_is_never_counted_as_overdue(): void
    {
        $this->coordinator();

        $this->task([
            'status' => Task::DONE, 'completed_at' => now(),
            'deadline' => now()->subMonth()->toDateString(),
        ]);

        $this->getJson($this->url('/summary'))->assertOk()->assertJsonPath('overdue', 0);
    }

    /** The counts must agree with the list they sit above. */
    public function test_the_summary_respects_the_same_scope_as_the_list(): void
    {
        $mine = $this->actor([Permission::TASKS_VIEW_OWN]);

        $this->task(['assigned_to' => $mine->id]);
        $this->task(['assigned_to' => $this->employee('Other')->id]);

        $this->getJson($this->url('/summary'))->assertOk()->assertJsonPath('total', 1);
    }

    // ── Sub-tasks ─────────────────────────────────────────────────────────

    /**
     * A sub-task is a task. The legacy's separate sub-phase table left one
     * carrying a name and nothing else — no owner, no date, no status.
     */
    public function test_a_subtask_inherits_the_kind_of_work_and_the_project(): void
    {
        $this->coordinator();
        $task = $this->task(['category' => 'support']);

        $this->postJson($this->url("/{$task->id}/subtasks"), [
            'title' => 'Create task flows', 'priority' => 'high',
        ])->assertOk()->assertJsonPath('data.subtasks.0.title', 'Create task flows');

        $child = Task::where('parent_task_id', $task->id)->firstOrFail();
        $this->assertSame('support', $child->category);
        $this->assertStringStartsWith('TK-', $child->reference);
    }

    // ── Dependencies ──────────────────────────────────────────────────────

    /**
     * 172 of the legacy's 203 dependencies were free text, not another task —
     * "awaiting the client's firewall change" and the like. Refusing those
     * would only mean the blocker goes unrecorded.
     */
    public function test_a_dependency_can_be_a_plain_note(): void
    {
        $this->coordinator();
        $task = $this->task();

        $this->postJson($this->url("/{$task->id}/dependencies"), [
            'note' => 'Awaiting the client’s firewall change window',
        ])->assertOk();

        $this->assertDatabaseHas('task_dependencies', [
            'task_id' => $task->id, 'kind' => 'external',
        ]);
    }

    public function test_a_task_cannot_wait_on_itself(): void
    {
        $this->coordinator();
        $task = $this->task();

        $this->postJson($this->url("/{$task->id}/dependencies"), [
            'depends_on_task_id' => $task->id,
        ])->assertJsonValidationErrorFor('depends_on_task_id');
    }

    // ── Comments ──────────────────────────────────────────────────────────

    /** 64,578 comments against 9,055 tasks — seven per task. Not a side tab. */
    public function test_a_comment_can_be_replied_to(): void
    {
        $this->coordinator();
        $task = $this->task();

        $this->postJson($this->url("/{$task->id}/comments"), [
            'body' => 'Make use of the shared components here.',
        ])->assertOk();

        $parent = $task->comments()->firstOrFail();

        $this->postJson($this->url("/{$task->id}/comments"), [
            'body' => 'Thanks for the reminder.', 'parent_id' => $parent->id,
        ])->assertOk()
            ->assertJsonCount(1, 'data.comments')
            ->assertJsonPath('data.comments.0.replies.0.body', 'Thanks for the reminder.');
    }

    /** Pinned first, then oldest to newest, so the thread still reads in order. */
    public function test_a_pinned_comment_leads_the_thread(): void
    {
        $this->coordinator();
        $task = $this->task();

        $this->postJson($this->url("/{$task->id}/comments"), ['body' => 'First'])->assertOk();
        $this->postJson($this->url("/{$task->id}/comments"), ['body' => 'Second'])->assertOk();

        $second = $task->comments()->where('body', 'Second')->firstOrFail();

        $this->postJson($this->url("/{$task->id}/comments/{$second->id}/pin"), ['is_pinned' => true])
            ->assertOk()
            ->assertJsonPath('data.comments.0.body', 'Second');
    }

    /**
     * The same call reacts and un-reacts, because to the person clicking it
     * the button is a toggle.
     */
    public function test_reacting_twice_takes_the_reaction_back(): void
    {
        $this->coordinator();
        $task = $this->task();

        $this->postJson($this->url("/{$task->id}/comments"), ['body' => 'Shipped'])->assertOk();
        $comment = $task->comments()->firstOrFail();

        $this->postJson($this->url("/{$task->id}/comments/{$comment->id}/react"), ['emoji' => '👍'])
            ->assertOk()->assertJsonPath('data.comments.0.reactions.👍', 1);

        $this->postJson($this->url("/{$task->id}/comments/{$comment->id}/react"), ['emoji' => '👍'])
            ->assertOk()->assertJsonPath('data.comments.0.reactions', []);
    }

    // ── Issues ────────────────────────────────────────────────────────────

    /**
     * The legacy escalated 212 of its 242 issues — 88%. Escalation is what
     * raising an issue usually means, so it happens in the same call.
     */
    public function test_raising_an_issue_escalates_it_in_the_same_breath(): void
    {
        $this->coordinator();
        $manager = $this->employee('Grace');
        $task    = $this->task();

        $this->postJson($this->url("/{$task->id}/issues"), [
            'name' => 'The client has not signed off the design',
            'details' => 'Blocking the build.', 'priority' => 'high',
            'escalate_to' => $manager->id,
        ])->assertOk()
            ->assertJsonPath('data.issues.0.priority', 'high')
            ->assertJsonPath('data.issues.0.escalations.0.to.name', 'Grace Person');
    }

    public function test_an_issue_can_be_raised_without_escalating_it(): void
    {
        $this->coordinator();
        $task = $this->task();

        $this->postJson($this->url("/{$task->id}/issues"), ['name' => 'Licence key missing'])
            ->assertOk()
            ->assertJsonCount(0, 'data.issues.0.escalations')
            ->assertJsonPath('data.issues.0.is_open', true);
    }

    /**
     * An issue is not the task's status. A task can be in progress and still
     * be stuck — the legacy left 135 of its 242 issues open.
     */
    public function test_open_issues_are_counted_apart_from_the_task_status(): void
    {
        $this->coordinator();
        $task = $this->task(['status' => 'in_progress']);

        $this->postJson($this->url("/{$task->id}/issues"), ['name' => 'One'])->assertOk();
        $this->postJson($this->url("/{$task->id}/issues"), ['name' => 'Two'])->assertOk();

        $this->getJson($this->url('/' . $task->id))
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.open_issue_count', 2);
    }

    /** Reopening clears the resolution, so it never outlives what it described. */
    public function test_reopening_an_issue_clears_who_resolved_it(): void
    {
        $me = $this->coordinator();
        $task = $this->task();

        $this->postJson($this->url("/{$task->id}/issues"), ['name' => 'Licence key missing'])->assertOk();
        $issue = $task->issues()->firstOrFail();

        $this->putJson($this->url("/{$task->id}/issues/{$issue->id}"), ['status' => 'resolved'])->assertOk();
        $this->assertSame($me->id, $issue->fresh()->resolved_by);

        $this->putJson($this->url("/{$task->id}/issues/{$issue->id}"), ['status' => 'open'])
            ->assertOk()->assertJsonPath('data.issues.0.is_open', true);

        $this->assertNull($issue->fresh()->resolved_at);
        $this->assertNull($issue->fresh()->resolved_by);
    }

    /** An issue that climbs past one person has two escalations, not one. */
    public function test_escalating_further_keeps_the_earlier_escalation(): void
    {
        $this->coordinator();
        $first  = $this->employee('Grace');
        $second = $this->employee('Luke');
        $task   = $this->task();

        $this->postJson($this->url("/{$task->id}/issues"), [
            'name' => 'Stuck', 'escalate_to' => $first->id,
        ])->assertOk();

        $issue = $task->issues()->firstOrFail();

        $this->postJson($this->url("/{$task->id}/issues/{$issue->id}/escalate"), [
            'employee_id' => $second->id, 'note' => 'No response in three days.',
        ])->assertOk()->assertJsonCount(2, 'data.issues.0.escalations');
    }

    // ── Deleting ──────────────────────────────────────────────────────────

    /** A sub-task without its parent is an orphan nothing can navigate to. */
    public function test_deleting_a_task_takes_its_subtasks_with_it(): void
    {
        $this->coordinator();
        $task = $this->task();

        $this->postJson($this->url("/{$task->id}/subtasks"), ['title' => 'Create task flows'])->assertOk();
        $child = Task::where('parent_task_id', $task->id)->firstOrFail();

        $this->deleteJson($this->url('/' . $task->id))->assertOk();

        // Soft-deleted, so a task removed by mistake is recoverable.
        $this->assertSoftDeleted('tasks', ['id' => $task->id]);
        $this->assertSoftDeleted('tasks', ['id' => $child->id]);
    }

    // ── Attachments ───────────────────────────────────────────────────────

    /** A file on a task with no project — three quarters of them. */
    public function test_a_file_can_be_attached_to_a_task_that_has_no_project(): void
    {
        Storage::fake('project_documents');

        $this->coordinator();
        $task = $this->task();

        $this->post(
            "/api/v1/tasks/{$task->id}/attachments?org={$this->org->slug}",
            ['file' => UploadedFile::fake()->create('requirements.pdf', 20, 'application/pdf')],
        )->assertOk()->assertJsonPath('data.attachments.0.original_name', 'requirements.pdf');

        $stored = $task->attachments()->firstOrFail();
        $this->assertNull($stored->project_id);
        $this->assertStringStartsWith(
            'KVAULT001:', Storage::disk('project_documents')->get($stored->storage_path),
        );
    }
}
