<?php

namespace Tests\Feature\Project;

use App\Models\Organization;
use App\Models\Project\Project;
use App\Models\Project\ProjectProfessionalService;
use App\Models\Project\ProjectSubcontractor;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Professional-services and subcontractor role lists. */
class ProjectResourceRolesTest extends TestCase
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

    private function url(string $path): string
    {
        return "/api/v1/projects/{$this->project->id}{$path}?org={$this->org->slug}";
    }

    public function test_a_professional_services_resource_is_added_and_listed(): void
    {
        $this->editor();

        $res = $this->postJson($this->url('/professional-services'), [
            'role' => 'sme', 'name' => 'Dr Otieno', 'organization' => 'Acme', 'contact_info' => 'o@acme.com',
        ])->assertCreated();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame('Subject Matter Expert (SME)', $res->json('data.0.role_label'));
        $this->assertNotEmpty($res->json('catalog'));
    }

    public function test_an_invalid_ps_role_is_rejected(): void
    {
        $this->editor();
        $this->postJson($this->url('/professional-services'), ['role' => 'wizard', 'name' => 'X'])
            ->assertStatus(422)->assertJsonValidationErrors('role');
    }

    public function test_a_subcontractor_carries_scope_contract_and_statuses(): void
    {
        $this->editor();

        $res = $this->postJson($this->url('/subcontractors'), [
            'role' => 'cabling', 'company' => 'WireWorks', 'scope_of_work' => 'Cabling',
            'contract_reference' => 'SC-1', 'approval_status' => 'approved', 'onboarding_status' => 'in_progress',
        ])->assertCreated();

        $row = $res->json('data.0');
        $this->assertSame('Cabling Contractor', $row['role_label']);
        $this->assertSame('approved', $row['approval_status']);
        $this->assertSame('in_progress', $row['onboarding_status']);

        $id = $row['id'];
        $this->deleteJson($this->url("/subcontractors/{$id}"))->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('project_subcontractors', 0);
    }
}
