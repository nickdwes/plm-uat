<?php

namespace Tests\Feature\Project;

use App\Models\Organization;
use App\Models\Project\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Expense tracking — category variance, burn, forecast and task/travel linkage. */
class ProjectExpenseAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
        $this->project = Project::create([
            'organization_id' => $this->org->id, 'name' => 'SD-WAN',
            'execution_start' => '2026-01-01', 'execution_end' => '2026-12-31',
        ]);

        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'T', 'scope' => 'organization',
            'permissions' => [Permission::PROJECTS_VIEW, Permission::PROJECTS_UPDATE],
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/projects/{$this->project->id}{$path}?org={$this->org->slug}";
    }

    public function test_variance_by_category_and_totals(): void
    {
        $this->postJson($this->url('/finance/budget-lines'), ['label' => 'Air fares', 'category' => 'travel', 'amount' => 100000])->assertCreated();
        $this->postJson($this->url('/finance/budget-lines'), ['label' => 'Kit', 'category' => 'procurement', 'amount' => 50000])->assertCreated();

        $this->postJson($this->url('/finance/expenses'), ['label' => 'Flight NBO-MBA', 'category' => 'travel', 'amount' => 120000, 'spent_on' => '2026-03-10'])->assertCreated();
        $this->postJson($this->url('/finance/expenses'), ['label' => 'Switch', 'category' => 'procurement', 'amount' => 30000, 'spent_on' => '2026-04-02'])->assertCreated();

        $a = $this->getJson($this->url('/finance/analytics'))->assertOk();

        $this->assertSame(150000.0, (float) $a->json('budgeted'));
        $this->assertSame(150000.0, (float) $a->json('spent'));

        $travel = collect($a->json('by_category'))->firstWhere('category', 'travel');
        $this->assertSame(120000.0, (float) $travel['spent']);
        $this->assertSame(-20000.0, (float) $travel['variance']);   // over on travel
        $this->assertTrue($travel['over']);

        $proc = collect($a->json('by_category'))->firstWhere('category', 'procurement');
        $this->assertSame(20000.0, (float) $proc['variance']);       // under on procurement
        $this->assertFalse($proc['over']);
    }

    public function test_monthly_burn_and_forecast(): void
    {
        $this->postJson($this->url('/finance/budget-lines'), ['label' => 'All in', 'amount' => 1200000])->assertCreated();
        $this->postJson($this->url('/finance/expenses'), ['label' => 'Jan spend', 'amount' => 100000, 'spent_on' => '2026-01-20'])->assertCreated();
        $this->postJson($this->url('/finance/expenses'), ['label' => 'Feb spend', 'amount' => 50000, 'spent_on' => '2026-02-15'])->assertCreated();

        $a = $this->getJson($this->url('/finance/analytics'))->assertOk();

        $burn = $a->json('burn');
        $this->assertCount(2, $burn);
        $this->assertSame('2026-01', $burn[0]['month']);
        $this->assertSame(150000.0, (float) $burn[1]['cumulative']);

        $this->assertNotNull($a->json('forecast'));
        $this->assertSame('2026-12-31', $a->json('forecast.window_end'));
    }

    public function test_expense_links_to_a_task_and_reports_linkage(): void
    {
        $task = $this->postJson($this->url('/tasks'), ['title' => 'Deploy'])->assertCreated()->json('data.id');

        $this->postJson($this->url('/finance/expenses'), [
            'label' => 'Deploy travel', 'category' => 'travel', 'amount' => 5000, 'task_id' => $task,
        ])->assertCreated();

        $snap = $this->getJson($this->url('/finance'))->assertOk();
        $exp  = collect($snap->json('expenses'))->firstWhere('label', 'Deploy travel');
        $this->assertSame($task, $exp['task_id']);
        $this->assertSame('Deploy', $exp['task']);

        $a = $this->getJson($this->url('/finance/analytics'))->assertOk();
        $this->assertSame(1, $a->json('linkage.to_tasks'));
        $this->assertSame(0, $a->json('linkage.unlinked'));
    }

    public function test_a_task_from_another_project_is_rejected(): void
    {
        $other = Project::create(['organization_id' => $this->org->id, 'name' => 'Other']);
        $foreignTask = $this->postJson("/api/v1/projects/{$other->id}/tasks?org={$this->org->slug}", ['title' => 'X'])
            ->assertCreated()->json('data.id');

        $this->postJson($this->url('/finance/expenses'), [
            'label' => 'Bad link', 'amount' => 100, 'task_id' => $foreignTask,
        ])->assertStatus(422);
    }
}
