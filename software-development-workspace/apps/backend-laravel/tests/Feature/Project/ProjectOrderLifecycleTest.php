<?php

namespace Tests\Feature\Project;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Project\Project;
use App\Models\Project\ProjectOrder;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Order acceptance stamping and the central audit trail on order changes. */
class ProjectOrderLifecycleTest extends TestCase
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

    private function anOrder(): ProjectOrder
    {
        $this->postJson($this->url('/bom/items'), ['category' => 'hardware', 'description' => 'Switch', 'quantity' => 1, 'unit_cost' => 100, 'selling_price' => 150])->assertCreated();
        $this->postJson($this->url('/bom/approve'))->assertOk();

        return ProjectOrder::where('project_id', $this->project->id)->firstOrFail();
    }

    public function test_accepting_stamps_the_customer_and_note(): void
    {
        $order = $this->anOrder();

        $res = $this->putJson($this->url("/orders/{$order->id}/status"), [
            'status' => 'accepted', 'acceptance_note' => 'Signed off by client on delivery.',
        ])->assertOk();

        $row = collect($res->json('data'))->firstWhere('id', $order->id);
        $this->assertSame('accepted', $row['status']);
        $this->assertSame('Approver Ann', $row['accepted_by']);
        $this->assertSame('Signed off by client on delivery.', $row['acceptance_note']);

        $order->refresh();
        $this->assertNotNull($order->accepted_at);
        $this->assertSame($this->user->id, $order->accepted_by);
    }

    public function test_order_changes_are_written_to_the_central_audit_log(): void
    {
        $order = $this->anOrder();

        $before = AuditLog::where('auditable_type', ProjectOrder::class)->where('action', 'updated')->count();
        $this->putJson($this->url("/orders/{$order->id}/status"), ['status' => 'delivered'])->assertOk();

        $logs = AuditLog::where('auditable_type', ProjectOrder::class)->where('auditable_id', $order->id)->get();
        // A 'created' from generation and an 'updated' from the status change.
        $this->assertTrue($logs->contains('action', 'created'));
        $updated = $logs->firstWhere('action', 'updated');
        $this->assertNotNull($updated);
        $this->assertSame(1, AuditLog::where('auditable_type', ProjectOrder::class)->where('action', 'updated')->count() - $before);
    }
}
