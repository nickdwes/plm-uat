<?php

namespace Tests\Feature\Project;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\Project\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The governance reports — generated from the structures already captured. */
class ProjectReportsTest extends TestCase
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
    }

    private function editor(): void
    {
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

    public function test_reports_return_all_six_views(): void
    {
        $this->editor();

        $res = $this->getJson($this->url('/reports'))->assertOk();

        foreach (['raci', 'org_chart', 'governance', 'stakeholders', 'customer_directory', 'escalation'] as $key) {
            $this->assertArrayHasKey($key, $res->json());
        }
        $this->assertCount(15, $res->json('governance'));
    }

    public function test_a_governance_holder_populates_the_raci_and_escalation(): void
    {
        $this->editor();
        $emp = Employee::create([
            'employee_number' => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id, 'first_name' => 'Pat', 'last_name' => 'M',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
        ]);

        $this->postJson($this->url('/governance'), [
            'role_key' => 'project_manager', 'assignee_employee_id' => $emp->id,
            'responsibility' => 'Runs delivery', 'escalation_authority' => 'To Director',
        ])->assertOk();

        $res = $this->getJson($this->url('/reports'))->assertOk();

        $this->assertSame('Pat M', $res->json('raci.0.person'));
        $this->assertSame('To Director', $res->json('escalation.0.escalates_to'));
    }

    public function test_risk_and_dependency_report_ranks_risks_and_flags_overdue_dependencies(): void
    {
        $this->editor();

        // Two risks — a high one (high×high=9) and a low one (low×low=1).
        \App\Models\Project\ProjectRisk::create([
            'organization_id' => $this->org->id, 'project_id' => $this->project->id,
            'title' => 'Vendor may slip', 'likelihood' => 'high', 'impact' => 'high', 'status' => 'open',
        ]);
        \App\Models\Project\ProjectRisk::create([
            'organization_id' => $this->org->id, 'project_id' => $this->project->id,
            'title' => 'Minor typo risk', 'likelihood' => 'low', 'impact' => 'low', 'status' => 'closed',
        ]);

        // An overdue, unresolved dependency and a resolved one.
        \App\Models\Project\ProjectDependency::create([
            'organization_id' => $this->org->id, 'project_id' => $this->project->id,
            'title' => 'Awaiting licence', 'kind' => 'vendor', 'status' => 'pending',
            'due_on' => now()->subDays(5)->toDateString(),
        ]);
        \App\Models\Project\ProjectDependency::create([
            'organization_id' => $this->org->id, 'project_id' => $this->project->id,
            'title' => 'Done thing', 'kind' => 'other', 'status' => 'resolved',
            'due_on' => now()->subDays(5)->toDateString(),
        ]);

        $rd = $this->getJson($this->url('/reports'))->assertOk()->json('risk_dependency');

        // Highest-heat risk first.
        $this->assertSame('Vendor may slip', $rd['risks'][0]['title']);
        $this->assertSame('high', $rd['risks'][0]['level']);
        $this->assertSame(9, $rd['risks'][0]['score']);

        $this->assertSame(2, $rd['summary']['risks_total']);
        $this->assertSame(1, $rd['summary']['risks_open']);      // the closed one excluded
        $this->assertSame(1, $rd['summary']['risks_high']);
        $this->assertSame(1, $rd['summary']['deps_overdue']);    // only the unresolved past-due one
        $this->assertSame(1, $rd['summary']['deps_unresolved']);
    }
}
