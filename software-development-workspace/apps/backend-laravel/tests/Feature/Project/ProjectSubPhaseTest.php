<?php

namespace Tests\Feature\Project;

use App\Models\Organization;
use App\Models\Project\Project;
use App\Models\Project\ProjectPhase;
use App\Models\Role;
use App\Models\User;
use App\Support\Account\DefaultReferenceData;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sub-phases — a phase can carry child phases, created against a top-level
 * phase of the same project, one level deep.
 */
class ProjectSubPhaseTest extends TestCase
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

        $this->project = Project::create(['organization_id' => $this->org->id, 'name' => 'SD-WAN']);
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

    private function url(string $path = ''): string
    {
        return "/api/v1/projects/{$this->project->id}/phases{$path}?org={$this->org->slug}";
    }

    private function phase(array $attrs = []): ProjectPhase
    {
        return ProjectPhase::create($attrs + [
            'organization_id' => $this->org->id, 'project_id' => $this->project->id,
            'name' => 'Design', 'slug' => 'design-' . fake()->unique()->numberBetween(1, 9999), 'position' => 0,
        ]);
    }

    public function test_a_sub_phase_is_created_under_its_parent(): void
    {
        $this->editor();
        $parent = $this->phase(['name' => 'Implementation']);

        $res = $this->postJson($this->url(), ['name' => 'Branch cut-over', 'parent_id' => $parent->id])->assertCreated();

        $row = collect($res->json('data'))->firstWhere('id', $parent->id);
        $this->assertCount(1, $row['children']);
        $this->assertSame('Branch cut-over', $row['children'][0]['name']);
    }

    public function test_a_sub_phase_cannot_hang_off_another_projects_phase(): void
    {
        $this->editor();
        $other = Project::create(['organization_id' => $this->org->id, 'name' => 'Elsewhere']);
        $theirs = ProjectPhase::create([
            'organization_id' => $this->org->id, 'project_id' => $other->id,
            'name' => 'Theirs', 'slug' => 'theirs', 'position' => 0,
        ]);

        $this->postJson($this->url(), ['name' => 'Wrong', 'parent_id' => $theirs->id])
            ->assertStatus(422)->assertJsonValidationErrors('parent_id');
    }

    public function test_a_sub_phase_cannot_itself_be_a_parent(): void
    {
        $this->editor();
        $parent = $this->phase(['name' => 'Implementation']);
        $child  = $this->phase(['name' => 'Cut-over', 'parent_id' => $parent->id]);

        // Nesting under a child (a sub-phase) is refused — one level only.
        $this->postJson($this->url(), ['name' => 'Deeper', 'parent_id' => $child->id])
            ->assertStatus(422)->assertJsonValidationErrors('parent_id');
    }
}
