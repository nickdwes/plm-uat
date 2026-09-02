<?php

namespace Tests\Feature\Project;

use App\Models\Organization;
use App\Models\Procurement\ProcurementRequest;
use App\Models\Project\Project;
use App\Models\Project\ProjectBudgetLine;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Budget generation — planned budget lines built from costed travel + procurement. */
class ProjectBudgetGenerationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Project $project;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
        $this->project = Project::create(['organization_id' => $this->org->id, 'name' => 'SD-WAN']);

        $this->user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'T', 'scope' => 'organization',
            'permissions' => [Permission::PROJECTS_VIEW, Permission::PROJECTS_UPDATE],
        ]);
        $this->user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $this->user->forgetCachedRoles();
        Sanctum::actingAs($this->user);
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/projects/{$this->project->id}{$path}?org={$this->org->slug}";
    }

    private function makeTravel(float $cost): void
    {
        $this->project->travelRequests()->create([
            'organization_id' => $this->org->id, 'reference' => 'TR-' . fake()->unique()->numberBetween(1, 9999),
            'purpose' => 'Travel to Mombasa', 'estimated_cost' => $cost, 'created_by' => $this->user->id,
        ]);
    }

    private function makeProc(float $cost, int $qty = 1): ProcurementRequest
    {
        return ProcurementRequest::create([
            'organization_id' => $this->org->id, 'context' => 'project', 'project_id' => $this->project->id,
            'reference' => 'PR-' . fake()->unique()->numberBetween(1, 9999), 'item' => 'Switch',
            'quantity' => $qty, 'estimated_cost' => $cost, 'created_by' => $this->user->id,
        ]);
    }

    public function test_it_builds_lines_from_travel_and_procurement(): void
    {
        $this->makeTravel(80000);
        $this->makeProc(50000, 2);   // 50k × 2 = 100k

        $res = $this->postJson($this->url('/finance/budget-lines/generate'))->assertOk();

        $this->assertSame(2, $res->json('generated.created'));

        $lines = collect($res->json('budget_lines'));
        $travel = $lines->firstWhere('source', 'travel');
        $proc   = $lines->firstWhere('source', 'procurement');
        $this->assertSame(80000.0, (float) $travel['amount']);
        $this->assertSame('travel', $travel['category']);
        $this->assertSame(100000.0, (float) $proc['amount']);   // quantity applied
    }

    public function test_regenerating_is_idempotent_and_updates_amounts(): void
    {
        $p = $this->makeProc(50000);
        $this->postJson($this->url('/finance/budget-lines/generate'))->assertOk();

        // First run created one line.
        $this->assertSame(1, ProjectBudgetLine::where('project_id', $this->project->id)->count());

        // Cost changes → regenerate updates, does not duplicate.
        $p->update(['estimated_cost' => 75000]);
        $res = $this->postJson($this->url('/finance/budget-lines/generate'))->assertOk();

        $this->assertSame(1, ProjectBudgetLine::where('project_id', $this->project->id)->count());
        $this->assertSame(1, $res->json('generated.updated'));
        $this->assertSame(0, $res->json('generated.created'));
        $this->assertSame(75000.0, (float) collect($res->json('budget_lines'))->firstWhere('source', 'procurement')['amount']);
    }

    public function test_a_removed_source_drops_its_generated_line_but_keeps_manual(): void
    {
        $this->project->budgetLines()->create(['organization_id' => $this->org->id, 'label' => 'Contingency', 'amount' => 10000]);
        $p = $this->makeProc(50000);
        $this->postJson($this->url('/finance/budget-lines/generate'))->assertOk();
        $this->assertSame(2, ProjectBudgetLine::where('project_id', $this->project->id)->count());

        // Cost cleared → the generated line goes; the manual line stays.
        $p->update(['estimated_cost' => null]);
        $res = $this->postJson($this->url('/finance/budget-lines/generate'))->assertOk();

        $this->assertSame(1, $res->json('generated.removed'));
        $labels = collect($res->json('budget_lines'))->pluck('label')->all();
        $this->assertSame(['Contingency'], $labels);
    }
}
