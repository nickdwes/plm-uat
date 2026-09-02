<?php

namespace Tests\Feature\Project;

use App\Models\Organization;
use App\Models\Procurement\ProcurementRequest;
use App\Models\Project\Project;
use App\Models\Project\ProjectMaterialOrder;
use App\Models\Project\ProjectOrder;
use App\Models\Project\ProjectVendorEngagement;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Approving a BOM generates order records and routes each to its module. */
class ProjectOrderGenerationTest extends TestCase
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

    private function addLine(array $body): void
    {
        $this->postJson($this->url('/bom/items'), $body)->assertCreated();
    }

    public function test_approving_generates_orders_and_routes_by_category(): void
    {
        $this->addLine(['category' => 'hardware', 'description' => 'Core switch', 'quantity' => 4, 'unit_cost' => 1200, 'selling_price' => 1800]);
        $this->addLine(['category' => 'software', 'description' => 'NMS licence', 'quantity' => 1, 'unit_cost' => 9000, 'selling_price' => 14000]);
        $this->addLine(['category' => 'professional_services', 'description' => 'Install consulting', 'quantity' => 1, 'unit_cost' => 5000, 'selling_price' => 8000]);
        $this->addLine(['category' => 'training', 'description' => 'Admin training', 'quantity' => 1, 'unit_cost' => 2000, 'selling_price' => 3000]);

        $res = $this->postJson($this->url('/bom/approve'))->assertOk();
        $this->assertSame(4, $res->json('generated.created'));
        $this->assertSame(3, $res->json('generated.spawned'));   // training spawns nothing

        // Each category routed to the right module record.
        $this->assertSame(1, ProjectMaterialOrder::where('project_id', $this->project->id)->count());
        $this->assertSame(1, ProcurementRequest::where('project_id', $this->project->id)->where('category', 'software')->count());
        $this->assertSame(1, ProjectVendorEngagement::where('project_id', $this->project->id)->count());

        $orders = $this->getJson($this->url('/orders'))->assertOk();
        $this->assertSame(4, $orders->json('summary.count'));
        $this->assertSame(3, $orders->json('summary.by_status.ordered'));   // hardware/software/PS
        // 7200 + 14000 + 8000 + 3000
        $this->assertSame(32200.0, (float) $orders->json('summary.total_value'));

        $hw = collect($orders->json('data'))->firstWhere('category', 'hardware');
        $this->assertSame('material_order', $hw['fulfilled_by']['kind']);
        $this->assertSame('ordered', $hw['status']);
        $training = collect($orders->json('data'))->firstWhere('category', 'training');
        $this->assertNull($training['fulfilled_by']);
        $this->assertSame('approved', $training['status']);
    }

    public function test_generation_is_idempotent(): void
    {
        $this->addLine(['category' => 'hardware', 'description' => 'Switch', 'quantity' => 1, 'unit_cost' => 100, 'selling_price' => 150]);

        $this->postJson($this->url('/bom/approve'))->assertOk();
        // Reopen + re-approve must not create a second order or material order.
        $this->postJson($this->url('/bom/reopen'))->assertOk();
        $res = $this->postJson($this->url('/bom/approve'))->assertOk();

        $this->assertSame(0, $res->json('generated.created'));
        $this->assertSame(1, $res->json('generated.existing'));
        $this->assertSame(1, ProjectOrder::where('project_id', $this->project->id)->count());
        $this->assertSame(1, ProjectMaterialOrder::where('project_id', $this->project->id)->count());
    }

    public function test_status_transition_stamps_the_date(): void
    {
        $this->addLine(['category' => 'hardware', 'description' => 'Switch', 'quantity' => 1, 'unit_cost' => 100, 'selling_price' => 150]);
        $this->postJson($this->url('/bom/approve'))->assertOk();
        $order = ProjectOrder::where('project_id', $this->project->id)->first();

        $this->putJson($this->url("/orders/{$order->id}/status"), ['status' => 'delivered'])->assertOk();

        $order->refresh();
        $this->assertSame('delivered', $order->status);
        $this->assertNotNull($order->delivered_at);
    }
}
