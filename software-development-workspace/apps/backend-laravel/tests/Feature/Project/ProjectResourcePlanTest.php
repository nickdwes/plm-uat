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

/** The resource planning register — effort, people, site and travel per task. */
class ProjectResourcePlanTest extends TestCase
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

    private function employee(): Employee
    {
        return Employee::create([
            'employee_number' => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id, 'first_name' => 'Jo', 'last_name' => 'Eng',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
        ]);
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/projects/{$this->project->id}{$path}?org={$this->org->slug}";
    }

    public function test_it_reports_effort_people_site_and_travel_per_task(): void
    {
        $emp = $this->employee();

        $this->postJson($this->url('/tasks'), [
            'title' => 'On-site cutover', 'effort_estimate_days' => 4,
            'assignees' => [['employee_id' => $emp->id, 'capacity' => 'assignee']],
            'logistics' => ['needs_travel' => true, 'travel_location' => 'Mombasa'],
        ])->assertCreated();
        $this->postJson($this->url('/tasks'), [
            'title' => 'Remote config', 'effort_estimate_days' => 2, 'logistics' => [],
        ])->assertCreated();

        $res = $this->getJson($this->url('/resource-plan'))->assertOk();

        $this->assertSame(6.0, (float) $res->json('summary.total_effort_days'));
        $this->assertSame(1, $res->json('summary.tasks_with_travel'));
        $this->assertSame(1, $res->json('summary.tasks_on_site'));

        $onsite = collect($res->json('data'))->firstWhere('title', 'On-site cutover');
        $this->assertSame('On-site', $onsite['site']);
        $this->assertSame('Mombasa', $onsite['location']);
        $this->assertTrue($onsite['travel']);
        $this->assertContains('Jo Eng', $onsite['people']);
        $this->assertSame(4.0, (float) $onsite['effort_days']);
    }

    public function test_over_allocation_across_teams_is_flagged(): void
    {
        $emp = $this->employee();
        $this->postJson($this->url('/team/roles/generate-teams'))->assertOk();
        $roles = \App\Models\Project\ProjectRole::where('project_id', $this->project->id)->take(2)->pluck('id');

        foreach ($roles as $rid) {
            $this->postJson($this->url('/team/people'), [
                'employee_id' => $emp->id, 'role_kind' => 'member', 'project_role_id' => $rid, 'capacity_percent' => 60,
            ])->assertCreated();
        }

        $res = $this->getJson($this->url('/resource-plan'))->assertOk();
        $this->assertSame(1, $res->json('summary.over_allocated'));
        $this->assertTrue(collect($res->json('utilization'))->firstWhere('name', 'Jo Eng')['over_allocated']);
    }
}
