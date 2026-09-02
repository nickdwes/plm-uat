<?php

namespace Tests\Feature\Project;

use App\Models\Organization;
use App\Models\Procurement\ProcurementRequest;
use App\Models\Project\Project;
use App\Models\Project\ProjectOrder;
use App\Models\Project\ProjectTravelRequest;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Raising the fulfilment record for training/travel orders left unrouted. */
class ProjectOrderRaiseTest extends TestCase
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

    private function orderFor(string $category): ProjectOrder
    {
        $this->postJson($this->url('/bom/reopen'));   // no-op if already draft
        $this->postJson($this->url('/bom/items'), ['category' => $category, 'description' => ucfirst($category) . ' item', 'quantity' => 1, 'unit_cost' => 2000, 'selling_price' => 3000])->assertCreated();
        $this->postJson($this->url('/bom/approve'))->assertOk();

        return ProjectOrder::where('project_id', $this->project->id)->where('category', $category)->firstOrFail();
    }

    public function test_raising_a_training_order_creates_a_service_procurement(): void
    {
        $order = $this->orderFor('training');
        $this->assertNull($order->fulfillable_id);

        $res = $this->postJson($this->url("/orders/{$order->id}/raise"))->assertOk();

        $row = collect($res->json('data'))->firstWhere('id', $order->id);
        $this->assertSame('training', $row['fulfilled_by']['kind']);
        $this->assertSame('ordered', $row['status']);
        $this->assertFalse($row['can_raise']);

        $f = \App\Models\Project\ProjectTrainingFulfillment::where('project_id', $this->project->id)->first();
        $this->assertNotNull($f);
        $this->assertSame(2000.0, (float) $f->cost_per_person);   // from the BOM unit_cost
    }

    public function test_raising_a_travel_order_creates_a_travel_request(): void
    {
        $order = $this->orderFor('travel_accommodation');

        $res = $this->postJson($this->url("/orders/{$order->id}/raise"))->assertOk();
        $row = collect($res->json('data'))->firstWhere('id', $order->id);
        $this->assertSame('travel', $row['fulfilled_by']['kind']);

        $this->assertSame(1, ProjectTravelRequest::where('project_id', $this->project->id)->count());
    }

    public function test_raising_is_idempotent(): void
    {
        $training = $this->orderFor('training');
        $this->postJson($this->url("/orders/{$training->id}/raise"))->assertOk();
        $this->postJson($this->url("/orders/{$training->id}/raise"))->assertOk();
        $this->assertSame(1, \App\Models\Project\ProjectTrainingFulfillment::where('project_id', $this->project->id)->count());

        // A hardware order is already routed at generation → raise is a no-op.
        $hardware = $this->orderFor('hardware');
        $link = $hardware->fulfillable_id;
        $this->postJson($this->url("/orders/{$hardware->id}/raise"))->assertOk();
        $this->assertSame($link, $hardware->fresh()->fulfillable_id);   // unchanged
    }
}
