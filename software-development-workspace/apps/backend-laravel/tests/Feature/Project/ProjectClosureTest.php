<?php

namespace Tests\Feature\Project;

use App\Models\Organization;
use App\Models\Project\Project;
use App\Models\Project\ProjectContractMilestone;
use App\Models\Project\ProjectOrder;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Financial closure — the gates that must be green before a project can close. */
class ProjectClosureTest extends TestCase
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

    private function url(string $path = ''): string
    {
        return "/api/v1/projects/{$this->project->id}{$path}?org={$this->org->slug}";
    }

    public function test_an_open_order_blocks_closure(): void
    {
        $this->postJson($this->url('/bom/items'), ['category' => 'hardware', 'description' => 'Switch', 'quantity' => 1, 'unit_cost' => 100, 'selling_price' => 150])->assertCreated();
        $this->postJson($this->url('/bom/approve'))->assertOk();   // order is 'ordered', not accepted

        $res = $this->getJson($this->url('/closure'))->assertOk();
        $this->assertFalse($res->json('ready'));
        $this->assertFalse($res->json('gates.orders_settled.met'));
        $this->assertSame(1, $res->json('gates.orders_settled.open'));

        $this->postJson($this->url('/closure/close'))->assertStatus(422);
    }

    public function test_over_budget_blocks_closure(): void
    {
        $this->project->budgetLines()->create(['organization_id' => $this->org->id, 'label' => 'Cap', 'currency' => 'KES', 'amount' => 100]);
        $this->project->expenses()->create(['organization_id' => $this->org->id, 'label' => 'Spend', 'currency' => 'KES', 'amount' => 150]);

        $res = $this->getJson($this->url('/closure'))->assertOk();
        $this->assertFalse($res->json('gates.within_budget.met'));
        $this->assertFalse($res->json('ready'));
    }

    public function test_all_gates_green_lets_the_project_close(): void
    {
        // One order, accepted; one billing milestone, paid; within budget.
        $this->postJson($this->url('/bom/items'), ['category' => 'hardware', 'description' => 'Switch', 'quantity' => 1, 'unit_cost' => 100, 'selling_price' => 150])->assertCreated();
        $this->postJson($this->url('/bom/approve'))->assertOk();
        $order = ProjectOrder::where('project_id', $this->project->id)->first();
        $this->putJson($this->url("/orders/{$order->id}/status"), ['status' => 'accepted'])->assertOk();

        ProjectContractMilestone::create([
            'organization_id' => $this->org->id, 'project_id' => $this->project->id,
            'name' => 'Final', 'value' => 1000, 'currency' => 'KES', 'status' => 'paid',
        ]);

        $ready = $this->getJson($this->url('/closure'))->assertOk();
        $this->assertTrue($ready->json('ready'));

        $closed = $this->postJson($this->url('/closure/close'))->assertOk();
        $this->assertTrue($closed->json('closed'));
        $this->assertNotNull($this->project->fresh()->financially_closed_at);

        // Closing again is refused (already closed → not ready).
        $this->postJson($this->url('/closure/close'))->assertStatus(422);
    }
}
