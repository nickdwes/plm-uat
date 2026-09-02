<?php

namespace Tests\Feature\Project;

use App\Models\Organization;
use App\Models\Project\Project;
use App\Models\Role;
use App\Models\Task\Task;
use App\Models\Task\TaskLogistics;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Travel requests generated from the travel schedule's cycles. */
class ProjectTravelRequestTest extends TestCase
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
            'permissions' => [Permission::PROJECTS_VIEW, Permission::PROJECTS_UPDATE],
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);
    }

    private function travelTask(string $title, string $location, string $from, string $to, bool $accom = false): void
    {
        $task = Task::create([
            'organization_id' => $this->org->id, 'project_id' => $this->project->id,
            'title' => $title, 'started_on' => $from, 'deadline' => $to,
        ]);
        TaskLogistics::create([
            'organization_id' => $this->org->id, 'task_id' => $task->id, 'project_id' => $this->project->id,
            'needs_travel' => true, 'needs_accommodation' => $accom, 'travel_location' => $location, 'travel_mode' => 'air',
        ]);
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/projects/{$this->project->id}/travel-requests{$path}?org={$this->org->slug}";
    }

    public function test_generation_makes_one_request_per_cycle_and_is_idempotent(): void
    {
        $this->travelTask('Survey', 'Mombasa', '2026-10-05', '2026-10-07', true);
        $this->travelTask('Install', 'Mombasa', '2026-10-06', '2026-10-08');   // merges with the above
        $this->travelTask('Kisumu', 'Kisumu', '2026-11-01', '2026-11-02');

        $res = $this->postJson($this->url('/generate'))->assertOk();
        $this->assertSame(2, $res->json('created'));
        $this->assertCount(2, $res->json('data'));

        $mombasa = collect($res->json('data'))->firstWhere('location', 'Mombasa');
        $this->assertStringContainsString('2 tasks', $mombasa['purpose']);
        $this->assertTrue($mombasa['accommodation']);

        // Running again adds nothing.
        $this->assertSame(0, $this->postJson($this->url('/generate'))->json('created'));
    }

    public function test_a_request_takes_a_cost_and_an_approval(): void
    {
        $this->travelTask('Survey', 'Mombasa', '2026-10-05', '2026-10-07');
        $id = $this->postJson($this->url('/generate'))->json('data.0.id');

        $this->putJson($this->url("/{$id}"), ['estimated_cost' => 180000])->assertOk();
        $res = $this->postJson($this->url("/{$id}/decision"), ['approval_status' => 'approved'])->assertOk();

        $this->assertSame(180000, (int) $res->json('summary.estimated_total'));
        $this->assertSame(0, $res->json('summary.pending_approval'));
    }
}
