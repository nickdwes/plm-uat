<?php

namespace Tests\Feature\Project;

use App\Models\Employee;
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

/** Travel is captured per task and consolidated into cost-effective cycles. */
class ProjectTravelScheduleTest extends TestCase
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
            'display_name' => 'T', 'scope' => 'organization', 'permissions' => [Permission::PROJECTS_VIEW],
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);
    }

    private function travelTask(string $title, string $location, string $mode, string $from, string $to, bool $accom = false): void
    {
        $task = Task::create([
            'organization_id' => $this->org->id, 'project_id' => $this->project->id,
            'title' => $title, 'started_on' => $from, 'deadline' => $to,
        ]);
        TaskLogistics::create([
            'organization_id' => $this->org->id, 'task_id' => $task->id, 'project_id' => $this->project->id,
            'needs_travel' => true, 'needs_accommodation' => $accom, 'travel_location' => $location, 'travel_mode' => $mode,
        ]);
    }

    public function test_same_site_overlapping_tasks_consolidate_into_one_cycle(): void
    {
        $this->travelTask('Survey', 'Mombasa', 'air', '2026-10-05', '2026-10-07', true);
        $this->travelTask('Install', 'Mombasa', 'air', '2026-10-06', '2026-10-08');
        $this->travelTask('Kisumu survey', 'Kisumu', 'road', '2026-11-01', '2026-11-02');

        $cycles = $this->getJson("/api/v1/projects/{$this->project->id}/travel-schedule?org={$this->org->slug}")
            ->assertOk()->json('cycles');

        $this->assertCount(2, $cycles);

        $mombasa = collect($cycles)->firstWhere('location', 'Mombasa');
        $this->assertCount(2, $mombasa['tasks']);        // the two overlapping trips merged
        $this->assertSame('2026-10-05', $mombasa['start']);
        $this->assertSame('2026-10-08', $mombasa['end']);
        $this->assertTrue($mombasa['accommodation']);
        $this->assertSame(2, $mombasa['travel_days']);   // air
    }

    public function test_same_site_but_distant_dates_stay_separate_cycles(): void
    {
        $this->travelTask('Phase 1', 'Nairobi', 'road', '2026-10-01', '2026-10-03');
        $this->travelTask('Phase 2', 'Nairobi', 'road', '2026-12-01', '2026-12-03');

        $cycles = $this->getJson("/api/v1/projects/{$this->project->id}/travel-schedule?org={$this->org->slug}")
            ->assertOk()->json('cycles');

        $this->assertCount(2, $cycles);   // not merged — the windows don't overlap
    }
}
