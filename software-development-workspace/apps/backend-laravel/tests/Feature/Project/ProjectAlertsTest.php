<?php

namespace Tests\Feature\Project;

use App\Models\Organization;
use App\Models\Procurement\ProcurementRequest;
use App\Models\Project\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Project controls & alerts — the conditions worth acting on. */
class ProjectAlertsTest extends TestCase
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

        $this->user = User::factory()->create();
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

    public function test_a_clean_project_has_no_alerts(): void
    {
        $res = $this->getJson($this->url('/alerts'))->assertOk();
        $this->assertSame(0, $res->json('summary.total'));
    }

    public function test_imminent_travel_and_pending_approval_raise_alerts(): void
    {
        $this->project->travelRequests()->create([
            'organization_id' => $this->org->id, 'reference' => 'TR-1',
            'purpose' => 'Travel to Mombasa', 'start_date' => now()->addDays(3)->toDateString(),
            'approval_status' => 'pending', 'created_by' => $this->user->id,
        ]);

        $res = $this->getJson($this->url('/alerts'))->assertOk();
        $cats = collect($res->json('data'));

        $soon = $cats->firstWhere('title', 'Travel starts within 7 days');
        $this->assertNotNull($soon);
        $this->assertSame('warning', $soon['severity']);

        $this->assertNotNull($cats->firstWhere('title', 'Travel approvals pending'));
    }

    public function test_long_lead_item_that_misses_its_date_is_critical(): void
    {
        // Needs it in 10 days, but the lead time is 45 — cannot arrive in time.
        ProcurementRequest::create([
            'organization_id' => $this->org->id, 'context' => 'project', 'project_id' => $this->project->id,
            'reference' => 'PR-1', 'item' => 'Core switch', 'lead_time_days' => 45,
            'required_by' => now()->addDays(10)->toDateString(), 'created_by' => $this->user->id,
        ]);

        $res = $this->getJson($this->url('/alerts'))->assertOk();
        $alert = collect($res->json('data'))->firstWhere('title', 'Long-lead items threaten the timeline');
        $this->assertNotNull($alert);
        $this->assertSame('critical', $alert['severity']);
        $this->assertSame(1, $res->json('summary.critical'));
    }

    public function test_actual_spend_over_budget_is_critical(): void
    {
        $this->project->budgetLines()->create(['organization_id' => $this->org->id, 'label' => 'All', 'currency' => 'KES', 'amount' => 100000]);
        $this->project->expenses()->create(['organization_id' => $this->org->id, 'label' => 'Spend', 'currency' => 'KES', 'amount' => 130000]);

        $res = $this->getJson($this->url('/alerts'))->assertOk();
        $alert = collect($res->json('data'))->firstWhere('category', 'budget');
        $this->assertNotNull($alert);
        $this->assertSame('critical', $alert['severity']);
        $this->assertStringContainsString('over budget', strtolower($alert['title']));
    }
}
