<?php

namespace Tests\Feature\Project;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\Project\Project;
use App\Models\Project\ProjectTask;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tasks on a project.
 *
 * The design's task board is the legacy's two-level work breakdown under
 * different names — 527 plan phases and 485 sub-phases map onto tasks and
 * sub-tasks — so the structure has to hold both. What is proved here is the
 * part that goes quietly wrong: progress that lies, a cycle nobody notices,
 * and a completion timestamp left on reopened work.
 */
class ProjectTaskTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('project_documents');

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);

        $this->project = Project::create([
            'organization_id' => $this->org->id, 'name' => 'Employee Portal Revamp',
        ]);
    }

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

    private function employee(): Employee
    {
        return Employee::create([
            'employee_number'         => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id,
            'first_name' => 'Test', 'last_name' => 'Person',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
        ]);
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/projects/{$this->project->id}{$path}?org={$this->org->slug}";
    }

    private function task(array $attrs = []): ProjectTask
    {
        return ProjectTask::create($attrs + [
            'organization_id' => $this->org->id,
            'project_id'      => $this->project->id,
            'title'           => 'Redesign messaging component library',
        ]);
    }

    private function editor(): Employee
    {
        return $this->actor([
            Permission::PROJECTS_VIEW, Permission::PROJECTS_UPDATE, Permission::PROJECTS_ASSIGN,
        ]);
    }

    // ── Tasks and sub-tasks ───────────────────────────────────────────────

    public function test_a_task_is_given_a_reference(): void
    {
        $this->editor();

        $res = $this->postJson($this->url('/tasks'), ['title' => 'Prototype the screens'])->assertCreated();

        $this->assertMatchesRegularExpression('/^TK-\d+$/', $res->json('data.reference'));
        $this->assertSame('todo', $res->json('data.status'));
    }

    public function test_a_sub_task_is_a_task_and_carries_everything_one_does(): void
    {
        /*
         * The legacy's sub-phase was its own table and held a name and nothing
         * else — no owner, no date, no status. One table with a parent is what
         * fixes that.
         */
        $this->editor();
        $parent = $this->task();

        $res = $this->postJson($this->url('/tasks'), [
            'title' => 'Sub work', 'parent_task_id' => $parent->id,
            'priority' => 'high', 'deadline' => '2026-02-22', 'status' => 'in_progress',
        ])->assertCreated();

        $this->assertSame('high', $res->json('data.priority'));
        $this->assertSame('2026-02-22', $res->json('data.deadline'));
        $this->assertSame($parent->id, $res->json('data.parent_task_id'));
    }

    public function test_sub_tasks_come_back_under_their_parent_not_beside_it(): void
    {
        $this->editor();
        $parent = $this->task();
        $this->task(['parent_task_id' => $parent->id, 'title' => 'Child']);

        $tasks = $this->getJson($this->url('/tasks'))->assertOk()->json('data');

        $this->assertCount(1, $tasks);
        $this->assertCount(1, $tasks[0]['subtasks']);
    }

    public function test_a_new_task_is_appended_rather_than_inserted(): void
    {
        $this->editor();
        $this->postJson($this->url('/tasks'), ['title' => 'First'])->assertCreated();
        $second = $this->postJson($this->url('/tasks'), ['title' => 'Second'])->assertCreated();

        $this->assertSame(2, $second->json('data.position'));
    }

    public function test_tasks_can_be_reordered_without_resending_them(): void
    {
        // Dragging is one gesture; sending each task back to express it is how
        // a drag blanks a description.
        $this->editor();
        $a = $this->task(['title' => 'A', 'position' => 1]);
        $b = $this->task(['title' => 'B', 'position' => 2]);

        $res = $this->postJson($this->url('/tasks/reorder'), ['order' => [$b->id, $a->id]])->assertOk();

        $this->assertSame('B', $res->json('data.0.title'));
        // The reorder touched position and nothing else.
        $this->assertSame('A', $a->fresh()->title);
    }

    // ── Completion ────────────────────────────────────────────────────────

    public function test_completing_a_task_stamps_when(): void
    {
        $this->editor();
        $t = $this->task();

        $res = $this->putJson($this->url("/tasks/{$t->id}"), [
            'title' => $t->title, 'status' => 'completed',
        ])->assertOk();

        $this->assertNotNull($res->json('data.completed_at'));
        $this->assertTrue($res->json('data.is_done'));
    }

    public function test_reopening_a_task_clears_the_completion(): void
    {
        // The same lie as a delivery date on a reopened project: it would
        // report finished work that is back in progress.
        $this->editor();
        $t = $this->task(['status' => 'completed', 'completed_at' => now()]);

        $res = $this->putJson($this->url("/tasks/{$t->id}"), [
            'title' => $t->title, 'status' => 'in_progress',
        ])->assertOk();

        $this->assertNull($res->json('data.completed_at'));
    }

    // ── Progress ──────────────────────────────────────────────────────────

    public function test_an_unplanned_project_has_no_progress_rather_than_zero(): void
    {
        // A project nobody has planned is not 0% complete, it is unplanned,
        // and a bar at zero says the wrong thing about it.
        $this->actor([Permission::PROJECTS_VIEW]);

        $this->getJson($this->url())->assertOk()->assertJsonPath('data.progress', null);
    }

    public function test_progress_counts_sub_tasks_too(): void
    {
        /*
         * Counting only top-level tasks would report a project as 0% while
         * nine of its ten sub-tasks are done — the opposite of what the number
         * is for.
         */
        $this->actor([Permission::PROJECTS_VIEW]);
        $parent = $this->task();
        $this->task(['parent_task_id' => $parent->id, 'status' => 'completed']);
        $this->task(['parent_task_id' => $parent->id, 'status' => 'completed']);
        $this->task(['parent_task_id' => $parent->id]);

        // 2 of 4 done, counting the parent.
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.progress', 50);
    }

    // ── Dependencies ──────────────────────────────────────────────────────

    public function test_a_task_can_wait_on_another(): void
    {
        $this->editor();
        $a = $this->task(['title' => 'A']);
        $b = $this->task(['title' => 'B']);

        $res = $this->postJson($this->url("/tasks/{$b->id}/dependencies"), [
            'depends_on_task_id' => $a->id,
        ])->assertOk();

        $this->assertCount(1, $res->json('data.dependencies'));
        // A is not done, so B reads as blocked.
        $this->assertTrue($res->json('data.is_blocked'));
    }

    public function test_a_task_cannot_wait_on_itself(): void
    {
        $this->editor();
        $t = $this->task();

        $this->postJson($this->url("/tasks/{$t->id}/dependencies"), ['depends_on_task_id' => $t->id])
            ->assertStatus(422)->assertJsonValidationErrors('depends_on_task_id');
    }

    public function test_a_dependency_cycle_is_refused(): void
    {
        // A deadlock nothing would ever surface, so it is refused rather than
        // recorded.
        $this->editor();
        $a = $this->task(['title' => 'A']);
        $b = $this->task(['title' => 'B']);
        $c = $this->task(['title' => 'C']);

        $this->postJson($this->url("/tasks/{$b->id}/dependencies"), ['depends_on_task_id' => $a->id])->assertOk();
        $this->postJson($this->url("/tasks/{$c->id}/dependencies"), ['depends_on_task_id' => $b->id])->assertOk();

        // A waiting on C would close the loop.
        $this->postJson($this->url("/tasks/{$a->id}/dependencies"), ['depends_on_task_id' => $c->id])
            ->assertStatus(422)->assertJsonValidationErrors('depends_on_task_id');
    }

    public function test_a_task_stops_being_blocked_when_its_dependency_completes(): void
    {
        $this->editor();
        $a = $this->task(['title' => 'A']);
        $b = $this->task(['title' => 'B']);

        $this->postJson($this->url("/tasks/{$b->id}/dependencies"), ['depends_on_task_id' => $a->id])->assertOk();
        $this->putJson($this->url("/tasks/{$a->id}"), ['title' => 'A', 'status' => 'completed'])->assertOk();

        $this->getJson($this->url("/tasks/{$b->id}"))->assertOk()->assertJsonPath('data.is_blocked', false);
    }

    public function test_a_task_cannot_depend_on_another_projects_task(): void
    {
        $this->editor();
        $other = Project::create(['organization_id' => $this->org->id, 'name' => 'Elsewhere']);
        $theirs = ProjectTask::create([
            'organization_id' => $this->org->id, 'project_id' => $other->id, 'title' => 'Theirs',
        ]);

        $this->postJson($this->url("/tasks/{$this->task()->id}/dependencies"), [
            'depends_on_task_id' => $theirs->id,
        ])->assertStatus(422)->assertJsonValidationErrors('depends_on_task_id');
    }

    // ── Members ───────────────────────────────────────────────────────────

    public function test_assignees_and_assisting_are_kept_apart(): void
    {
        // The design shows them separately; one table with a capacity keeps
        // them from disagreeing about who is on the task.
        $this->editor();
        $t = $this->task();

        $this->postJson($this->url("/tasks/{$t->id}/members"), [
            'employee_id' => $this->employee()->id, 'capacity' => 'assignee',
        ])->assertOk();

        $res = $this->postJson($this->url("/tasks/{$t->id}/members"), [
            'employee_id' => $this->employee()->id, 'capacity' => 'assisting',
        ])->assertOk();

        $this->assertCount(1, $res->json('data.assignees'));
        $this->assertCount(1, $res->json('data.assisting'));
    }

    public function test_putting_somebody_on_a_task_needs_the_staffing_authority(): void
    {
        $this->actor([Permission::PROJECTS_VIEW, Permission::PROJECTS_UPDATE]);

        $this->postJson($this->url("/tasks/{$this->task()->id}/members"), [
            'employee_id' => $this->employee()->id,
        ])->assertForbidden();
    }

    // ── Comments ──────────────────────────────────────────────────────────

    public function test_commenting_does_not_require_editing_the_project(): void
    {
        // Anybody who can see it should be able to say something about it.
        $this->actor([Permission::PROJECTS_VIEW]);

        $this->postJson($this->url('/comments'), ['body' => 'Remember to use components'])->assertOk();
    }

    public function test_replies_thread_one_level_and_no_deeper(): void
    {
        /*
         * A reply to a reply attaches to the top of that thread. Unbounded
         * nesting reads badly in a narrow column and nobody has asked for the
         * fourth level.
         */
        $this->actor([Permission::PROJECTS_VIEW]);

        $top = $this->postJson($this->url('/comments'), ['body' => 'Top'])->json('data.0.id');
        $this->postJson($this->url('/comments'), ['body' => 'Reply', 'parent_id' => $top])->assertOk();

        $comments = $this->getJson($this->url('/comments'))->assertOk()->json('data');
        $replyId  = $comments[0]['replies'][0]['id'];

        $this->postJson($this->url('/comments'), ['body' => 'Reply to reply', 'parent_id' => $replyId])->assertOk();

        $comments = $this->getJson($this->url('/comments'))->assertOk()->json('data');

        $this->assertCount(1, $comments);
        $this->assertCount(2, $comments[0]['replies']);
        $this->assertSame([], $comments[0]['replies'][0]['replies']);
    }

    // ── Resources ─────────────────────────────────────────────────────────

    public function test_a_resource_is_encrypted_like_every_other_document(): void
    {
        $this->editor();

        $this->post($this->url('/attachments'), [
            'file' => UploadedFile::fake()->createWithContent('scope.pdf', 'SCOPE OF WORK'),
        ])->assertOk();

        $path = $this->project->attachments()->first()->storage_path;
        $raw  = Storage::disk('project_documents')->get($path);

        $this->assertStringNotContainsString('SCOPE OF WORK', $raw);
    }

    public function test_a_resource_is_not_reachable_without_seeing_the_project(): void
    {
        $this->editor();

        $this->post($this->url('/attachments'), [
            'file' => UploadedFile::fake()->create('scope.pdf', 20, 'application/pdf'),
        ])->assertOk();

        $id = $this->project->attachments()->first()->id;
        $this->get($this->url("/attachments/{$id}/download"))->assertOk();

        $this->actor(['leave.view']);
        $this->get($this->url("/attachments/{$id}/download"))->assertForbidden();
    }

    // ── Tenancy ───────────────────────────────────────────────────────────

    public function test_a_task_of_another_project_is_not_reachable(): void
    {
        $this->editor();
        $other = Project::create(['organization_id' => $this->org->id, 'name' => 'Elsewhere']);
        $theirs = ProjectTask::create([
            'organization_id' => $this->org->id, 'project_id' => $other->id, 'title' => 'Theirs',
        ]);

        $this->putJson($this->url("/tasks/{$theirs->id}"), ['title' => 'Hijacked'])->assertNotFound();
    }
}
