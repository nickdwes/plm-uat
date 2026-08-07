<?php

namespace Tests\Feature\Task;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\Project\Project;
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
 * Tasks across the system, not one project's.
 *
 * The legacy carried 9,055 tasks of which only a quarter had a project, so the
 * first thing proved here is that a task can exist without one. The rest covers
 * the three behaviours it used heavily and never modelled: asking somebody
 * rather than assigning them, seeing what has been asked of you, and naming the
 * documents a task cannot close without.
 */
class TaskBoardTest extends TestCase
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

    // ── A task need not belong to a project ───────────────────────────────

    public function test_a_task_can_be_raised_without_a_project(): void
    {
        $this->coordinator();

        $response = $this->postJson($this->url(), [
            'title'    => 'Prepare the quarterly vendor review pack',
            'category' => 'internal',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.project', null)
            ->assertJsonPath('data.category', 'internal');

        $this->assertNull(Task::first()->project_id);
    }

    public function test_a_task_is_given_a_reference(): void
    {
        $this->coordinator();

        $this->postJson($this->url(), ['title' => 'Chase the signed ToR'])->assertCreated();

        $this->assertStringStartsWith('TK-', Task::first()->reference);
    }

    /**
     * The category is the module map. Twelve of them in the legacy, and each
     * one is where the task came from — a support ticket, a training booking,
     * a meeting. Filtering by it is how anyone finds their own kind of work.
     */
    public function test_tasks_are_filtered_by_category(): void
    {
        $this->coordinator();

        $this->task(['category' => 'support', 'title' => 'Site down at Westlands']);
        $this->task(['category' => 'training', 'title' => 'Book the Azure course']);

        $response = $this->getJson($this->url() . '&category=support');

        $response->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Site down at Westlands');
    }

    public function test_a_task_can_still_belong_to_a_project(): void
    {
        $this->coordinator();

        $project = Project::create([
            'organization_id' => $this->org->id, 'name' => 'Employee Portal Revamp',
        ]);

        $this->postJson($this->url(), [
            'title' => 'Cable the second floor', 'project_id' => $project->id, 'category' => 'project',
        ])->assertCreated()->assertJsonPath('data.project.name', 'Employee Portal Revamp');
    }

    // ── Effort ────────────────────────────────────────────────────────────

    /**
     * Nine legacy tasks in ten carried an effort estimate and an end date, and
     * nothing ever compared them. This is that comparison.
     */
    public function test_effort_variance_compares_the_estimate_against_the_days_spent(): void
    {
        $this->coordinator();

        $task = $this->task([
            'effort_estimate_days' => 3,
            'started_on'           => now()->subDays(8)->toDateString(),
            'status'               => Task::DONE,
            'completed_at'         => now()->subDays(3),
        ]);

        $response = $this->getJson($this->url('/' . $task->id));

        $response->assertOk()->assertJsonPath('data.elapsed_days', 5);
        // Two days over the three estimated.
        $this->assertSame(2.0, (float) $response->json('data.effort_variance'));
    }

    /**
     * No estimate is not a variance of zero. Zero says it came in exactly on
     * plan; null says nobody said what the plan was, and reporting cannot tell
     * them apart if both arrive as 0.
     */
    public function test_a_task_with_no_estimate_has_no_variance(): void
    {
        $this->coordinator();

        $task = $this->task(['started_on' => now()->subDays(4)->toDateString()]);

        $this->getJson($this->url('/' . $task->id))
            ->assertOk()
            ->assertJsonPath('data.effort_variance', null);
    }

    // ── Asking rather than assigning ──────────────────────────────────────

    /**
     * The legacy's "helping" was accepted 98% of the time, and the reason is
     * that it was a request. Accepting one adds a helper without moving
     * ownership — the task still has one accountable owner.
     */
    public function test_accepting_an_assist_adds_a_helper_without_moving_ownership(): void
    {
        $owner  = $this->coordinator();
        $helper = $this->employee('Helper');

        $task = $this->task(['assigned_to' => $owner->id]);

        $this->postJson($this->url("/{$task->id}/requests"), [
            'kind' => 'assist', 'to_employee_id' => $helper->id,
            'request_comment' => 'Can you take the firewall rules?',
        ])->assertOk();

        $request = $task->requests()->firstOrFail();
        $this->respondAs($helper, $task, $request->id, 'accepted');

        $this->assertSame($owner->id, $task->fresh()->assigned_to);
        $this->assertTrue(
            $task->members()->where('employee_id', $helper->id)->where('capacity', 'assisting')->exists(),
        );
    }

    /** Delegation is the one that moves ownership — and only once accepted. */
    public function test_accepting_a_delegation_moves_ownership(): void
    {
        $owner    = $this->coordinator();
        $receiver = $this->employee('Receiver');

        $task = $this->task(['assigned_to' => $owner->id]);

        $this->postJson($this->url("/{$task->id}/requests"), [
            'kind' => 'delegate', 'to_employee_id' => $receiver->id,
        ])->assertOk();

        $this->assertSame($owner->id, $task->fresh()->assigned_to, 'Asking is not assigning.');

        $this->respondAs($receiver, $task, $task->requests()->value('id'), 'accepted');

        $this->assertSame($receiver->id, $task->fresh()->assigned_to);
    }

    public function test_a_declined_delegation_leaves_ownership_alone(): void
    {
        $owner    = $this->coordinator();
        $receiver = $this->employee('Receiver');

        $task = $this->task(['assigned_to' => $owner->id]);
        $this->postJson($this->url("/{$task->id}/requests"), [
            'kind' => 'delegate', 'to_employee_id' => $receiver->id,
        ])->assertOk();

        $this->respondAs($receiver, $task, $task->requests()->value('id'), 'declined');

        $this->assertSame($owner->id, $task->fresh()->assigned_to);
    }

    /** Otherwise it is an assignment with extra steps. */
    public function test_only_the_person_asked_can_answer(): void
    {
        $owner    = $this->coordinator();
        $receiver = $this->employee('Receiver');

        $task = $this->task(['assigned_to' => $owner->id]);
        $this->postJson($this->url("/{$task->id}/requests"), [
            'kind' => 'delegate', 'to_employee_id' => $receiver->id,
        ])->assertOk();

        // Still acting as the owner, who did the asking.
        $this->postJson($this->url("/{$task->id}/requests/" . $task->requests()->value('id') . '/respond'), [
            'status' => 'accepted',
        ])->assertForbidden();
    }

    public function test_a_task_cannot_be_delegated_to_the_person_already_on_it(): void
    {
        $owner = $this->coordinator();
        $task  = $this->task(['assigned_to' => $owner->id]);

        $this->postJson($this->url("/{$task->id}/requests"), [
            'kind' => 'delegate', 'to_employee_id' => $owner->id,
        ])->assertJsonValidationErrorFor('to_employee_id');
    }

    /** Asking twice is a nudge, not a second request. */
    public function test_asking_again_reopens_rather_than_duplicating(): void
    {
        $owner    = $this->coordinator();
        $receiver = $this->employee('Receiver');

        $task = $this->task(['assigned_to' => $owner->id]);

        $this->postJson($this->url("/{$task->id}/requests"), [
            'kind' => 'assist', 'to_employee_id' => $receiver->id,
        ])->assertOk();

        $this->respondAs($receiver, $task, $task->requests()->value('id'), 'declined');
        Sanctum::actingAs($owner->fresh()->user);

        $this->postJson($this->url("/{$task->id}/requests"), [
            'kind' => 'assist', 'to_employee_id' => $receiver->id,
            'request_comment' => 'Reconsider — the client moved the date.',
        ])->assertOk();

        $this->assertSame(1, $task->requests()->count());

        $request = $task->requests()->firstOrFail();
        $this->assertSame('pending', $request->status);
        $this->assertNull($request->responded_at, 'The old answer must not survive the new ask.');
    }

    // ── The inbox ─────────────────────────────────────────────────────────

    /**
     * The legacy left 261 of its 354 delegations unanswered because nothing
     * ever told anybody they had been asked. This endpoint is that screen.
     */
    public function test_the_inbox_shows_what_was_asked_of_me_and_what_i_asked_of_others(): void
    {
        $owner    = $this->coordinator();
        $receiver = $this->employee('Receiver');

        $task = $this->task(['assigned_to' => $owner->id]);
        $this->postJson($this->url("/{$task->id}/requests"), [
            'kind' => 'delegate', 'to_employee_id' => $receiver->id,
        ])->assertOk();

        $this->getJson($this->url('/inbox'))
            ->assertOk()
            ->assertJsonCount(0, 'incoming')
            ->assertJsonCount(1, 'outgoing')
            ->assertJsonPath('outgoing.0.to.name', 'Receiver Person');

        $receiverUser = User::factory()->create();
        $receiver->update(['user_id' => $receiverUser->id]);
        $receiverUser->roles()->attach(Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Test', 'scope' => 'organization',
            'permissions' => [Permission::TASKS_VIEW_OWN],
        ])->id, ['organization_id' => $this->org->id]);
        $receiverUser->forgetCachedRoles();
        Sanctum::actingAs($receiverUser);

        $this->getJson($this->url('/inbox'))
            ->assertOk()
            ->assertJsonCount(1, 'incoming')
            ->assertJsonCount(0, 'outgoing');
    }

    /** An answered request has left the inbox; it is not still waiting. */
    public function test_the_inbox_holds_only_unanswered_requests(): void
    {
        $owner    = $this->coordinator();
        $receiver = $this->employee('Receiver');

        $task = $this->task(['assigned_to' => $owner->id]);
        $this->postJson($this->url("/{$task->id}/requests"), [
            'kind' => 'assist', 'to_employee_id' => $receiver->id,
        ])->assertOk();

        $this->respondAs($receiver, $task, $task->requests()->value('id'), 'accepted');
        Sanctum::actingAs($owner->fresh()->user);

        $this->getJson($this->url('/inbox'))->assertOk()->assertJsonCount(0, 'outgoing');
    }

    // ── Required documents ────────────────────────────────────────────────

    /**
     * "This task needs a signed ToR" is a checklist item whether or not the
     * document exists yet. The legacy required 344 and only 247 arrived; a
     * list that cannot express the requirement loses the other 97 silently.
     */
    public function test_a_required_document_can_be_named_before_it_exists(): void
    {
        $this->coordinator();
        $task = $this->task();

        $this->postJson($this->url("/{$task->id}/required-documents"), [
            'name' => 'Signed terms of reference',
        ])->assertOk()
            ->assertJsonPath('data.required_documents.0.name', 'Signed terms of reference')
            ->assertJsonPath('data.required_documents.0.is_fulfilled', false);
    }

    /**
     * A requirement on a task with no project is still tickable.
     *
     * Three quarters of legacy tasks had no project, so a requirement that
     * could only be met by a project attachment could never be satisfied on
     * most of them — the slot carries its own encrypted file instead.
     */
    public function test_a_requirement_is_satisfied_by_uploading_the_document(): void
    {
        Storage::fake('project_documents');

        $this->coordinator();
        $task = $this->task();

        $this->postJson($this->url("/{$task->id}/required-documents"), [
            'name' => 'Signed terms of reference',
        ])->assertOk();

        $document = $task->requiredDocuments()->firstOrFail();

        $this->post(
            "/api/v1/tasks/{$task->id}/required-documents/{$document->id}/fulfil?org={$this->org->slug}",
            ['file' => UploadedFile::fake()->create('tor.pdf', 12, 'application/pdf')],
        )->assertOk()->assertJsonPath('data.required_documents.0.is_fulfilled', true);

        $this->assertNull($document->fresh()->project_attachment_id, 'No project, no project attachment.');
        $this->assertNotNull($document->fresh()->storage_path);
    }

    /** Nothing reaches the bucket in the clear, on this path either. */
    public function test_the_uploaded_document_is_encrypted_at_rest(): void
    {
        Storage::fake('project_documents');

        $this->coordinator();
        $task = $this->task();

        $this->postJson($this->url("/{$task->id}/required-documents"), ['name' => 'Signed ToR'])->assertOk();
        $document = $task->requiredDocuments()->firstOrFail();

        $this->post(
            "/api/v1/tasks/{$task->id}/required-documents/{$document->id}/fulfil?org={$this->org->slug}",
            ['file' => UploadedFile::fake()->create('tor.pdf', 12, 'application/pdf')],
        )->assertOk();

        $stored = Storage::disk('project_documents')->get($document->fresh()->storage_path);
        $this->assertStringStartsWith('KVAULT001:', $stored);
    }

    // ── Scope ─────────────────────────────────────────────────────────────

    /**
     * Somebody holding only `view_own` sees what they are doing, helping with,
     * or have been asked about — not the whole company's workload.
     */
    public function test_view_own_shows_only_my_work(): void
    {
        $mine = $this->actor([Permission::TASKS_VIEW_OWN]);

        $this->task(['assigned_to' => $mine->id, 'title' => 'Mine']);
        $this->task(['assigned_to' => $this->employee('Other')->id, 'title' => 'Theirs']);

        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Mine');
    }

    public function test_a_task_from_another_organization_is_not_visible(): void
    {
        $this->coordinator();

        $other = Organization::create(['name' => 'Beta', 'slug' => 'beta']);
        $task  = Task::withoutGlobalScopes()->create([
            'organization_id' => $other->id, 'title' => 'Somebody else’s work',
        ]);

        $this->getJson($this->url('/' . $task->id))->assertNotFound();
    }

    /** Reopening clears the finished timestamp; a stale one reads as done. */
    public function test_reopening_a_task_clears_its_completion(): void
    {
        $this->coordinator();
        $task = $this->task(['status' => Task::DONE, 'completed_at' => now()]);

        $this->putJson($this->url('/' . $task->id), [
            'title' => $task->title, 'status' => 'in_progress',
        ])->assertOk()->assertJsonPath('data.completed_at', null);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** Answer a request as the person it was addressed to. */
    private function respondAs(Employee $employee, Task $task, int $requestId, string $status): void
    {
        $user = $employee->user ?? User::factory()->create();

        if ($employee->user_id === null) {
            $employee->update(['user_id' => $user->id]);
            $role = Role::create([
                'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
                'display_name' => 'Test', 'scope' => 'organization',
                'permissions' => [Permission::TASKS_VIEW_OWN],
            ]);
            $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
            $user->forgetCachedRoles();
        }

        Sanctum::actingAs($user);

        $this->postJson($this->url("/{$task->id}/requests/{$requestId}/respond"), [
            'status' => $status,
        ])->assertOk();
    }
}
