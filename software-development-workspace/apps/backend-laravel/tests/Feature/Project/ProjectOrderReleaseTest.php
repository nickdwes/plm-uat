<?php

namespace Tests\Feature\Project;

use App\Models\Organization;
use App\Models\Project\Project;
use App\Models\Project\ProjectOrder;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The order-release gate — when a project may begin ordering. */
class ProjectOrderReleaseTest extends TestCase
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

    private function addLineAndApprove(): void
    {
        $this->postJson($this->url('/bom/items'), ['category' => 'hardware', 'description' => 'Switch', 'quantity' => 1, 'unit_cost' => 100, 'selling_price' => 150])->assertCreated();
        $this->postJson($this->url('/bom/approve'))->assertOk();
    }

    private function orderCount(): int
    {
        return ProjectOrder::where('project_id', $this->project->id)->count();
    }

    public function test_manual_gate_generates_on_approve(): void
    {
        // Default gate is 'manual'.
        $res = $this->postJson($this->url('/bom/items'), ['category' => 'hardware', 'description' => 'X', 'quantity' => 1])->assertCreated();
        $this->postJson($this->url('/bom/approve'))->assertOk()->assertJsonPath('held', false);
        $this->assertSame(1, $this->orderCount());
    }

    public function test_on_award_holds_until_the_project_is_awarded(): void
    {
        $this->putJson($this->url('/order-release'), ['order_release_gate' => 'on_award'])->assertOk();

        // Project still in kickoff → not awarded → approving holds the orders.
        $this->postJson($this->url('/bom/items'), ['category' => 'hardware', 'description' => 'X', 'quantity' => 1])->assertCreated();
        $this->postJson($this->url('/bom/approve'))->assertOk()
            ->assertJsonPath('held', true)
            ->assertJsonPath('readiness.awarded', false);
        $this->assertSame(0, $this->orderCount());

        // Moving past kickoff (the award) releases ordering.
        $this->project->update(['status' => 'in_flight']);
        $this->postJson($this->url('/orders/generate'))->assertOk();
        $this->assertSame(1, $this->orderCount());
    }

    public function test_on_contract_releases_when_contract_is_confirmed_ready(): void
    {
        $this->project->update(['status' => 'in_flight']);   // awarded
        $this->putJson($this->url('/order-release'), ['order_release_gate' => 'on_contract'])->assertOk()
            ->assertJsonPath('readiness.ready', false);

        $this->addLineAndApprove();
        $this->assertSame(0, $this->orderCount());   // held — no ready contract

        // Confirming the contract releases the held BOM automatically.
        $res = $this->putJson($this->url('/order-release'), ['contract_ready_override' => true])->assertOk();
        $this->assertNotNull($res->json('generated'));
        $this->assertSame(1, $this->orderCount());
    }

    public function test_on_lpo_releases_when_the_customer_lpo_is_recorded(): void
    {
        $this->project->update(['status' => 'in_flight']);
        $this->putJson($this->url('/order-release'), ['order_release_gate' => 'on_lpo'])->assertOk();
        $this->addLineAndApprove();
        $this->assertSame(0, $this->orderCount());

        $this->putJson($this->url('/order-release'), [
            'customer_lpo_number' => 'PO-99213', 'customer_lpo_value' => 4200000, 'customer_lpo_currency' => 'KES',
        ])->assertOk();
        $this->assertSame(1, $this->orderCount());
    }
}
