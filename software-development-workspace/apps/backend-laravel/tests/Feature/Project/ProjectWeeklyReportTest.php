<?php

namespace Tests\Feature\Project;

use App\Models\Organization;
use App\Models\Project\Project;
use App\Models\Role;
use App\Models\Task\Task;
use App\Models\User;
use App\Support\Account\DefaultReferenceData;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 3: the weekly report — RAG, OKRs, and published week-over-week history.
 *
 * The point being proved is the fix for the old PLM's copy-paste bug: last week
 * is a recorded snapshot, not a live recompute, so a later change to the project
 * does not rewrite the past. Also that RAG follows progress, and that an OKR is
 * a task a PM flagged.
 */
class ProjectWeeklyReportTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
        DefaultReferenceData::seedFor($this->org->id);

        $this->project = Project::create([
            'organization_id' => $this->org->id, 'name' => 'SD-WAN Rollout',
        ]);
    }

    private function actor(array $permissions): void
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Test', 'scope' => 'organization', 'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);
    }

    private function url(string $path): string
    {
        return "/api/v1/projects/{$this->project->id}{$path}?org={$this->org->slug}";
    }

    private function task(array $attrs = []): Task
    {
        return Task::create($attrs + [
            'organization_id' => $this->org->id,
            'category' => 'project', 'project_id' => $this->project->id,
            'title' => 'A task', 'status' => 'todo',
        ]);
    }

    public function test_rag_follows_progress_and_counts_are_live(): void
    {
        $this->actor([Permission::PROJECTS_VIEW, Permission::PROJECTS_UPDATE]);

        $this->task(['status' => 'completed']);
        $this->task(['status' => 'completed']);
        $this->task(["status" => "todo"]); // 2/3 done = 67% → amber

        $res = $this->getJson($this->url('/weekly-report'))->assertOk();

        $this->assertSame(67, $res->json('data.live.progress_pct'));
        $this->assertSame('amber', $res->json('data.live.rag'));
        $this->assertSame(2, $res->json('data.live.completed_tasks'));
        $this->assertSame(3, $res->json('data.live.total_tasks'));
        $this->assertFalse($res->json('data.published'));
    }

    public function test_okrs_are_flagged_tasks(): void
    {
        $this->actor([Permission::PROJECTS_VIEW, Permission::PROJECTS_UPDATE]);

        $this->task(['title' => 'Cut over the first branch', 'weekly_okr' => true]);
        $this->task(['title' => 'Ordinary task', 'weekly_okr' => false]);

        $res = $this->getJson($this->url('/weekly-report'))->assertOk();

        $okrs = $res->json('data.live.okrs');
        $this->assertCount(1, $okrs);
        $this->assertSame('Cut over the first branch', $okrs[0]['title']);
    }

    public function test_publishing_records_an_immutable_snapshot_used_as_last_week(): void
    {
        $this->actor([Permission::PROJECTS_VIEW, Permission::PROJECTS_UPDATE]);

        // Week 1 state: 1 of 2 done = 50%.
        $a = $this->task(['status' => 'completed']);
        $b = $this->task(['status' => 'todo']);

        // Publish this week, then back-date it to last week so it is "history".
        $this->postJson($this->url('/weekly-report/publish'), ['summary' => 'Slow start'])
            ->assertCreated()->assertJsonPath('data.progress_pct', 50);

        $this->project->weeklySnapshots()->update([
            'week_start' => now()->startOfWeek()->subWeek()->toDateString(),
        ]);

        // Now finish the second task — this week is 100%.
        $b->update(['status' => 'completed']);

        $res = $this->getJson($this->url('/weekly-report'))->assertOk();

        // Live is 100% green; last week stayed the recorded 50% — not rewritten.
        $this->assertSame(100, $res->json('data.live.progress_pct'));
        $this->assertSame('green', $res->json('data.live.rag'));
        $this->assertSame(50, $res->json('data.last_week.progress_pct'));
        $this->assertSame(50, $res->json('data.deltas.progress_pct')); // +50
        $this->assertSame('Slow start', $res->json('data.last_week.summary'));

        // The project carries the latest published RAG.
        $this->assertNotNull($this->project->fresh()->rag);
    }

    public function test_publishing_the_same_week_twice_overwrites_not_duplicates(): void
    {
        $this->actor([Permission::PROJECTS_VIEW, Permission::PROJECTS_UPDATE]);
        $this->task(['status' => 'completed']);

        $this->postJson($this->url('/weekly-report/publish'), [])->assertCreated();
        $this->postJson($this->url('/weekly-report/publish'), ['summary' => 'Second take'])->assertCreated();

        $history = $this->getJson($this->url('/weekly-report/history'))->assertOk();
        $this->assertCount(1, $history->json('data'));
        $this->assertSame('Second take', $history->json('data.0.summary'));
    }

    public function test_publishing_needs_the_update_permission(): void
    {
        $this->actor([Permission::PROJECTS_VIEW]);

        $this->postJson($this->url('/weekly-report/publish'), [])->assertForbidden();
    }

    public function test_the_report_exports_as_a_pdf(): void
    {
        $this->actor([Permission::PROJECTS_VIEW, Permission::PROJECTS_UPDATE]);
        $this->task(['status' => 'completed']);
        $this->task(['title' => 'Cut over', 'weekly_okr' => true]);
        $this->postJson($this->url('/weekly-report/publish'), ['summary' => 'On track this week.'])->assertCreated();

        $res = $this->get($this->url('/weekly-report/pdf'));

        $res->assertOk();
        $res->assertHeader('Content-Type', 'application/pdf');
        // A real PDF, and a sensibly named download.
        $this->assertStringStartsWith('%PDF', $res->getContent());
        $this->assertStringContainsString('Weekly-Report-', $res->headers->get('Content-Disposition'));
    }

    public function test_the_pdf_needs_view_permission(): void
    {
        // A member of the org (so the org resolves) but with no project rights.
        $this->actor([]);

        $this->get($this->url('/weekly-report/pdf'))->assertForbidden();
    }
}
