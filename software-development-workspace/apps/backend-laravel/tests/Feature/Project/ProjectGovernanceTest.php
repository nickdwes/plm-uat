<?php

namespace Tests\Feature\Project;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\Project\Project;
use App\Models\Project\ProjectGovernanceRole;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The project governance committee — the fixed catalogue of roles, who holds
 * each with what authority, and the advisory warning when a mandatory seat is
 * empty.
 */
class ProjectGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
        $this->project = Project::create(['organization_id' => $this->org->id, 'name' => 'Versa SD-WAN']);
    }

    private function editor(): void
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Test', 'scope' => 'organization',
            'permissions' => [Permission::PROJECTS_VIEW, Permission::PROJECTS_UPDATE],
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);
    }

    private function employee(): Employee
    {
        return Employee::create([
            'employee_number' => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id, 'first_name' => 'Jane', 'last_name' => 'Doe',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
        ]);
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/projects/{$this->project->id}/governance{$path}?org={$this->org->slug}";
    }

    public function test_the_committee_lists_all_roles_and_warns_on_empty_mandatory_seats(): void
    {
        $this->editor();

        $res = $this->getJson($this->url())->assertOk();

        $this->assertCount(count(ProjectGovernanceRole::CATALOG), $res->json('data'));
        // Every mandatory seat is empty on a fresh project → all warned.
        $this->assertCount(count(ProjectGovernanceRole::MANDATORY), $res->json('vacant_mandatory'));
    }

    public function test_a_seat_is_set_with_person_authority_and_backup(): void
    {
        $this->editor();
        $pm = $this->employee();
        $backup = $this->employee();

        $res = $this->postJson($this->url(), [
            'role_key' => 'project_manager',
            'assignee_employee_id' => $pm->id,
            'backup_employee_id'   => $backup->id,
            'responsibility'       => 'Runs delivery',
            'approval_authority'   => 'Up to KES 500k',
            'escalation_authority' => 'To Project Director',
        ])->assertOk();

        $pmRole = collect($res->json('data'))->firstWhere('role_key', 'project_manager');
        $this->assertSame($pm->id, $pmRole['assignee']['id']);
        $this->assertSame($backup->id, $pmRole['backup']['id']);
        $this->assertSame('Up to KES 500k', $pmRole['approval_authority']);
        $this->assertTrue($pmRole['filled']);

        // Project Manager no longer appears in the warning.
        $this->assertNotContains('Project Manager', $res->json('vacant_mandatory'));
    }

    public function test_the_template_seeds_the_mandatory_seats(): void
    {
        $this->editor();

        $this->postJson($this->url('/generate-template'))->assertOk();

        $this->assertSame(
            count(ProjectGovernanceRole::MANDATORY),
            ProjectGovernanceRole::where('project_id', $this->project->id)->count(),
        );
    }

    public function test_a_project_cannot_be_initiated_with_vacant_mandatory_governance(): void
    {
        $this->editor();
        $this->project->update(['status' => 'kickoff']);

        // No governance yet → initiation refused.
        $this->putJson("/api/v1/projects/{$this->project->id}?org={$this->org->slug}", [
            'name' => $this->project->name, 'status' => 'in_flight',
        ])->assertStatus(422)->assertJsonValidationErrors('status');

        // Fill every mandatory seat → initiation allowed.
        $emp = $this->employee();
        foreach (ProjectGovernanceRole::MANDATORY as $key) {
            $this->postJson($this->url(), ['role_key' => $key, 'assignee_employee_id' => $emp->id])->assertOk();
        }

        $this->putJson("/api/v1/projects/{$this->project->id}?org={$this->org->slug}", [
            'name' => $this->project->name, 'status' => 'in_flight',
        ])->assertOk();
    }

    public function test_setting_a_seat_needs_the_update_permission(): void
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'v' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'V', 'scope' => 'organization', 'permissions' => [Permission::PROJECTS_VIEW],
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);

        $this->postJson($this->url(), ['role_key' => 'project_manager'])->assertForbidden();
    }
}
