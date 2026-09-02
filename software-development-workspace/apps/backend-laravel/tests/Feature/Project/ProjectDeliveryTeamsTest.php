<?php

namespace Tests\Feature\Project;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\Project\Project;
use App\Models\Project\ProjectRole;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Delivery teams — the ten standing teams, and members carrying a lead flag,
 * capacity and an assignment window.
 */
class ProjectDeliveryTeamsTest extends TestCase
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

    public function test_the_ten_standing_teams_are_generated_with_descriptions(): void
    {
        $this->editor();

        $res = $this->postJson($this->url('/team/roles/generate-teams'))->assertOk();

        $this->assertCount(count(ProjectRole::TEAM_CATALOG), $res->json('data.roles'));
        $this->assertNotNull($res->json('data.roles.0.team_key'));
        $this->assertNotNull($res->json('data.roles.0.description'));

        // Idempotent — running again does not duplicate.
        $again = $this->postJson($this->url('/team/roles/generate-teams'))->assertOk();
        $this->assertCount(count(ProjectRole::TEAM_CATALOG), $again->json('data.roles'));
    }

    public function test_a_member_is_added_with_lead_capacity_and_dates(): void
    {
        $this->editor();
        $emp = $this->employee();
        $this->postJson($this->url('/team/roles/generate-teams'))->assertOk();
        $roleId = ProjectRole::where('project_id', $this->project->id)->first()->id;

        $res = $this->postJson($this->url('/team/people'), [
            'employee_id' => $emp->id, 'role_kind' => 'member', 'project_role_id' => $roleId,
            'is_lead' => true, 'capacity_percent' => 60, 'assigned_from' => '2026-10-01', 'assigned_to' => '2026-12-31',
        ])->assertCreated();

        $member = collect($res->json('data.roles'))->firstWhere('id', $roleId)['members'][0];
        $this->assertTrue($member['is_lead']);
        $this->assertSame(60, $member['capacity_percent']);
        $this->assertSame('2026-10-01', $member['assigned_from']);
    }

    public function test_over_allocation_across_projects_is_warned_not_blocked(): void
    {
        $this->editor();
        $emp = $this->employee();

        // 60% on this project.
        $this->postJson($this->url('/team/roles/generate-teams'))->assertOk();
        $roleA = ProjectRole::where('project_id', $this->project->id)->first()->id;
        $this->postJson($this->url('/team/people'), [
            'employee_id' => $emp->id, 'role_kind' => 'member', 'project_role_id' => $roleA, 'capacity_percent' => 60,
        ])->assertCreated()->assertJsonPath('warning', null);

        // Another 60% on a second live project → 120%, warned but saved.
        $projectB = Project::create(['organization_id' => $this->org->id, 'name' => 'B']);
        $projectB->roles()->create(['organization_id' => $this->org->id, 'name' => 'Team', 'position' => 0]);
        $roleB = $projectB->roles()->first()->id;

        $res = $this->postJson("/api/v1/projects/{$projectB->id}/team/people?org={$this->org->slug}", [
            'employee_id' => $emp->id, 'role_kind' => 'member', 'project_role_id' => $roleB, 'capacity_percent' => 60,
        ])->assertCreated();

        $this->assertStringContainsString('120%', $res->json('warning'));
    }

    public function test_capacity_over_100_is_rejected(): void
    {
        $this->editor();
        $emp = $this->employee();
        $this->postJson($this->url('/team/roles/generate-teams'))->assertOk();
        $roleId = ProjectRole::where('project_id', $this->project->id)->first()->id;

        $this->postJson($this->url('/team/people'), [
            'employee_id' => $emp->id, 'role_kind' => 'member', 'project_role_id' => $roleId, 'capacity_percent' => 150,
        ])->assertStatus(422)->assertJsonValidationErrors('capacity_percent');
    }
}
