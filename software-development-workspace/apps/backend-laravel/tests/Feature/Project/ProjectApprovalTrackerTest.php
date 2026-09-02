<?php

namespace Tests\Feature\Project;

use App\Models\Organization;
use App\Models\Procurement\ProcurementRequest;
use App\Models\Project\Project;
use App\Models\Project\ProjectTravelRequest;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The approval tracker — one queue over travel + procurement sign-offs. */
class ProjectApprovalTrackerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Project $project;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
        $this->project = Project::create(['organization_id' => $this->org->id, 'name' => 'SD-WAN']);

        $this->user = User::factory()->create(['name' => 'Approver Ann']);
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'T', 'scope' => 'organization',
            'permissions' => [Permission::PROJECTS_VIEW, Permission::PROJECTS_UPDATE],
        ]);
        $this->user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $this->user->forgetCachedRoles();
        Sanctum::actingAs($this->user);
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/projects/{$this->project->id}{$path}?org={$this->org->slug}";
    }

    private function makeTravel(array $attrs = []): ProjectTravelRequest
    {
        return $this->project->travelRequests()->create(array_merge([
            'organization_id' => $this->org->id, 'reference' => 'TR-1',
            'purpose' => 'Travel to Mombasa', 'location' => 'Mombasa',
            'estimated_cost' => 80000, 'personnel' => ['Jo'], 'created_by' => $this->user->id,
        ], $attrs));
    }

    private function makeProc(array $attrs = []): ProcurementRequest
    {
        return ProcurementRequest::create(array_merge([
            'organization_id' => $this->org->id, 'context' => 'project', 'project_id' => $this->project->id,
            'reference' => 'PR-1', 'item' => 'Core switch', 'estimated_cost' => 200000,
            'lead_time_days' => 45, 'created_by' => $this->user->id,
        ], $attrs));
    }

    public function test_it_lists_travel_and_procurement_in_one_queue(): void
    {
        $this->makeTravel();
        $this->makeProc();

        $res = $this->getJson($this->url('/approvals'))->assertOk();

        $this->assertSame(2, $res->json('summary.total'));
        $this->assertSame(2, $res->json('summary.pending'));
        $this->assertSame(280000.0, (float) $res->json('summary.pending_value'));

        $kinds = collect($res->json('data'))->pluck('kind')->sort()->values()->all();
        $this->assertSame(['procurement', 'travel'], $kinds);

        $proc = collect($res->json('data'))->firstWhere('kind', 'procurement');
        $this->assertContains('long-lead', $proc['flags']);   // 45-day lead
    }

    public function test_deciding_records_who_and_when_and_updates_the_summary(): void
    {
        $t = $this->makeTravel();

        $res = $this->postJson($this->url("/approvals/travel/{$t->id}/decision"), ['approval_status' => 'approved'])->assertOk();

        $this->assertSame(0, $res->json('summary.pending'));
        $this->assertSame(1, $res->json('summary.approved'));

        $row = collect($res->json('data'))->firstWhere('kind', 'travel');
        $this->assertSame('approved', $row['status']);
        $this->assertSame('Approver Ann', $row['decided_by']);
        $this->assertNotNull($row['decided_at']);

        $this->assertDatabaseHas('project_travel_requests', [
            'id' => $t->id, 'approval_status' => 'approved', 'decided_by' => $this->user->id,
        ]);
    }

    public function test_reopening_clears_the_decider(): void
    {
        $p = $this->makeProc(['approval_status' => 'approved', 'decided_by' => $this->user->id, 'decided_at' => now()]);

        $this->postJson($this->url("/approvals/procurement/{$p->id}/decision"), ['approval_status' => 'pending'])->assertOk();

        $this->assertDatabaseHas('procurement_requests', [
            'id' => $p->id, 'approval_status' => 'pending', 'decided_by' => null,
        ]);
    }

    public function test_an_unknown_kind_is_404_and_cross_project_id_is_404(): void
    {
        $this->postJson($this->url('/approvals/widget/1/decision'), ['approval_status' => 'approved'])->assertNotFound();

        $other = Project::create(['organization_id' => $this->org->id, 'name' => 'Other']);
        $foreign = ProcurementRequest::create([
            'organization_id' => $this->org->id, 'context' => 'project', 'project_id' => $other->id,
            'reference' => 'PR-9', 'item' => 'Foreign', 'created_by' => $this->user->id,
        ]);
        $this->postJson($this->url("/approvals/procurement/{$foreign->id}/decision"), ['approval_status' => 'approved'])->assertNotFound();
    }
}
