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

/** Travel cycle optimisation tips — recommendations over the consolidated cycles. */
class ProjectTravelOptimizationTest extends TestCase
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

    private function travelTask(string $title, string $location, string $start, string $end): void
    {
        $this->postJson($this->url('/tasks'), [
            'title' => $title,
            'started_on' => $start, 'deadline' => $end,
            'logistics' => ['needs_travel' => true, 'travel_location' => $location, 'travel_mode' => 'air'],
        ])->assertCreated();
    }

    public function test_two_near_trips_to_the_same_site_are_flagged_mergeable(): void
    {
        // Two separate, non-overlapping windows to Mombasa, 5 days apart → mergeable.
        $this->travelTask('Survey', 'Mombasa', '2026-09-01', '2026-09-03');
        $this->travelTask('Install', 'Mombasa', '2026-09-08', '2026-09-10');

        $res = $this->getJson($this->url('/travel-optimization'))->assertOk();

        $merge = collect($res->json('data'))->firstWhere('type', 'merge');
        $this->assertNotNull($merge);
        $this->assertGreaterThan(0, $res->json('summary.potential_saving_days'));
    }

    public function test_a_single_task_trip_is_flagged_underfilled(): void
    {
        $this->travelTask('Lone visit', 'Kisumu', '2026-09-01', '2026-09-02');

        $res = $this->getJson($this->url('/travel-optimization'))->assertOk();

        $batch = collect($res->json('data'))->firstWhere('type', 'batch');
        $this->assertNotNull($batch);
        $this->assertSame([1], $batch['cycles']);
    }

    public function test_no_travel_yields_no_tips(): void
    {
        $res = $this->getJson($this->url('/travel-optimization'))->assertOk();
        $this->assertSame(0, $res->json('summary.tips'));
        $this->assertSame(0, $res->json('summary.cycles'));
    }
}
