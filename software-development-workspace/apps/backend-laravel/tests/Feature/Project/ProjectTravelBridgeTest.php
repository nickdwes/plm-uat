<?php

namespace Tests\Feature\Project;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\Project\Project;
use App\Models\Role;
use App\Models\Travel\TravelDestination;
use App\Models\Travel\TravelRequest;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The bridge — a project travel cycle raised into the corporate travel module. */
class ProjectTravelBridgeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Project $project;
    private Employee $engineer;

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
            'permissions' => [Permission::PROJECTS_VIEW, Permission::PROJECTS_UPDATE, Permission::PROJECTS_ASSIGN, Permission::TRAVEL_VIEW],
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);

        $this->engineer = Employee::create([
            'employee_number' => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id, 'first_name' => 'Net', 'last_name' => 'Eng',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
        ]);
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/projects/{$this->project->id}{$path}?org={$this->org->slug}";
    }

    /** Expense categories + allowance rates + one approval stage — the org config the journey needs. */
    private function seedTravelConfig(): void
    {
        foreach (['Per diem' => 4500, 'Accommodation' => 12000] as $name => $amount) {
            $cat = \App\Models\Travel\TravelExpenseCategory::create(['organization_id' => $this->org->id, 'name' => $name, 'is_active' => true]);
            \App\Models\Travel\TravelAllowanceRate::create([
                'organization_id' => $this->org->id, 'travel_expense_category_id' => $cat->id,
                'amount' => $amount, 'currency' => 'KES', 'unit' => 'per_day',
                'effective_from' => now()->subYear()->toDateString(),
            ]);
        }
        \App\Models\Travel\TravelApprovalStage::create([
            'organization_id' => $this->org->id, 'stage' => 'department_head', 'sequence' => 1, 'is_active' => true,
        ]);
    }

    /** A travel-flagged task (with a subtask, in a phase) assigned to the engineer, then a generated cycle. */
    private function generatedCycle(): int
    {
        $phase = \App\Models\Project\ProjectPhase::create(['organization_id' => $this->org->id, 'project_id' => $this->project->id, 'name' => 'Assessment', 'slug' => 'assessment', 'position' => 1]);

        $taskId = $this->postJson($this->url('/tasks'), [
            'title' => 'Site assessment', 'started_on' => '2026-09-21', 'deadline' => '2026-09-26', 'phase_id' => $phase->id,
            'assignees' => [['employee_id' => $this->engineer->id, 'capacity' => 'assignee']],
            'logistics' => ['needs_travel' => true, 'travel_location' => 'Mombasa', 'travel_mode' => 'air', 'needs_accommodation' => true],
        ])->assertCreated()->json('data.id');

        // A subtask of the site task — must appear in the itinerary too.
        \App\Models\Task\Task::create([
            'organization_id' => $this->org->id, 'project_id' => $this->project->id, 'parent_task_id' => $taskId,
            'title' => 'LAN testing', 'reference' => 'TK-SUB', 'phase_id' => $phase->id,
            'started_on' => '2026-09-22', 'deadline' => '2026-09-23', 'position' => 1, 'status' => 'open',
        ]);

        $this->postJson($this->url('/travel-requests/generate'))->assertOk();

        return \App\Models\Project\ProjectTravelRequest::where('project_id', $this->project->id)->firstOrFail()->id;
    }

    public function test_raising_builds_the_full_journey_costed_and_submitted(): void
    {
        TravelDestination::create(['organization_id' => $this->org->id, 'name' => 'Mombasa', 'country' => 'KE', 'is_international' => false]);
        $this->seedTravelConfig();
        $ptrId = $this->generatedCycle();   // 21→26 Sep = 6 days

        $res = $this->postJson($this->url("/travel-requests/{$ptrId}/raise-in-module"))->assertOk();
        $this->assertSame(1, $res->json('raised.raised'));
        $this->assertSame(1, $res->json('raised.submitted'));   // took the full journey into approval

        $tr = TravelRequest::where('project_id', $this->project->id)->with('items', 'transportLegs', 'itinerary')->first();
        $this->assertNotNull($tr);
        $this->assertSame($this->engineer->id, $tr->employee_id);
        $this->assertSame($ptrId, $tr->source_project_travel_request_id);
        $this->assertStringContainsString('Mombasa', $tr->purpose);

        // Allowance lines were costed from the rate card (per-diem + accommodation), and a leg added.
        $this->assertSame(2, $tr->items->count());
        $this->assertGreaterThan(0, (float) $tr->total_claimed);   // 4500×6 + 12000×6
        $this->assertSame(1, $tr->transportLegs->count());
        $this->assertSame('in_approval', $tr->status);            // submitted, following the standard journey

        // The itinerary expands to the task AND its subtask, each dated & phase-labelled.
        $this->assertSame(2, $tr->itinerary->count());
        $lines = $tr->itinerary->sortBy('position')->values();
        $this->assertStringContainsString('Site assessment', $lines[0]->activity);
        $this->assertSame('2026-09-21', $lines[0]->starts_on->toDateString());
        $this->assertSame('Assessment', $lines[0]->category);           // phase name
        $this->assertStringContainsString('↳', $lines[1]->activity);    // subtask, indented
        $this->assertStringContainsString('LAN testing', $lines[1]->activity);
        $this->assertSame('2026-09-22', $lines[1]->starts_on->toDateString());

        $row = collect($res->json('data'))->firstWhere('id', $ptrId);
        $this->assertSame(1, $row['module_requests']);
    }

    public function test_raising_is_idempotent(): void
    {
        TravelDestination::create(['organization_id' => $this->org->id, 'name' => 'Mombasa', 'country' => 'KE', 'is_international' => false]);
        $ptrId = $this->generatedCycle();

        $this->postJson($this->url("/travel-requests/{$ptrId}/raise-in-module"))->assertOk();
        $res = $this->postJson($this->url("/travel-requests/{$ptrId}/raise-in-module"))->assertOk();

        $this->assertSame(0, $res->json('raised.raised'));
        $this->assertSame(1, $res->json('raised.existing'));
        $this->assertSame(1, TravelRequest::where('source_project_travel_request_id', $ptrId)->count());
    }

    public function test_the_module_list_can_filter_by_project(): void
    {
        TravelDestination::create(['organization_id' => $this->org->id, 'name' => 'Mombasa', 'country' => 'KE', 'is_international' => false]);
        $ptrId = $this->generatedCycle();
        $this->postJson($this->url("/travel-requests/{$ptrId}/raise-in-module"))->assertOk();

        $list = $this->getJson("/api/v1/travel-requests?org={$this->org->slug}&project_id={$this->project->id}")->assertOk();
        $this->assertCount(1, $list->json('data'));
        $this->assertSame($this->project->id, $list->json('data.0.project_id'));
    }
}
