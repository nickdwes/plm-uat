<?php

namespace Tests\Feature\Project;

use App\Models\Organization;
use App\Models\Procurement\ProcurementRequest;
use App\Models\Project\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The project logistics summary — the whole logistics posture in one rollup. */
class ProjectLogisticsSummaryTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
        $this->project = Project::create(['organization_id' => $this->org->id, 'name' => 'SD-WAN']);

        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'T', 'scope' => 'organization',
            'permissions' => [Permission::PROJECTS_VIEW, Permission::PROJECTS_UPDATE, Permission::PROJECTS_ASSIGN],
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/projects/{$this->project->id}{$path}?org={$this->org->slug}";
    }

    public function test_it_rolls_up_requirements_travel_procurement_and_resourcing(): void
    {
        // A task that needs travel + carries effort.
        $this->postJson($this->url('/tasks'), [
            'title' => 'On-site cutover', 'effort_estimate_days' => 5,
            'logistics' => ['needs_travel' => true, 'travel_location' => 'Mombasa'],
        ])->assertCreated();
        // A remote task.
        $this->postJson($this->url('/tasks'), ['title' => 'Remote config', 'effort_estimate_days' => 2, 'logistics' => []])->assertCreated();

        ProcurementRequest::create([
            'organization_id' => $this->org->id, 'context' => 'project', 'project_id' => $this->project->id,
            'reference' => 'PR-1', 'item' => 'Switch', 'estimated_cost' => 90000, 'lead_time_days' => 45,
        ]);

        $s = $this->getJson($this->url('/logistics-summary'))->assertOk();

        $this->assertSame(1, $s->json('requirements.needs_travel'));
        $this->assertSame(1, $s->json('requirements.tasks_with_needs'));

        $this->assertSame(1, $s->json('resourcing.on_site'));
        $this->assertSame(1, $s->json('resourcing.remote'));
        $this->assertSame(7.0, (float) $s->json('resourcing.effort_days'));

        $this->assertSame(1, $s->json('procurement.items'));
        $this->assertSame(1, $s->json('procurement.long_lead'));
        $this->assertSame(90000.0, (float) $s->json('procurement.estimated_total'));

        $this->assertSame(1, $s->json('travel.cycles'));       // one travel-flagged task → one cycle
        $this->assertSame(1, $s->json('travel.sites'));
    }
}
