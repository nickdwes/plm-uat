<?php

namespace Tests\Feature\Project;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\Project\Project;
use App\Models\Project\ProjectMaterialOrder;
use App\Models\Role;
use App\Models\Training\VendorPartner;
use App\Models\User;
use App\Support\Account\DefaultReferenceData;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Supplier material ordering & delivery — an order with its tracker, delivery
 * updates, logistics contacts and documents.
 */
class ProjectMaterialOrderTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
        DefaultReferenceData::seedFor($this->org->id);

        $this->project = Project::create(['organization_id' => $this->org->id, 'name' => 'SD-WAN Rollout']);
    }

    private function editor(): void
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Test', 'scope' => 'organization',
            'permissions' => [Permission::PROJECTS_VIEW, Permission::PROJECTS_UPDATE],
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Employee::create([
            'employee_number' => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id, 'first_name' => 'T', 'last_name' => 'P',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01', 'user_id' => $user->id,
        ]);
        Sanctum::actingAs($user);
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/projects/{$this->project->id}/material-orders{$path}?org={$this->org->slug}";
    }

    private function order(): int
    {
        return $this->postJson($this->url(), ['name' => 'Cisco routers — 12 branches'])->json('data.id');
    }

    public function test_an_order_is_referenced_and_links_a_supplier(): void
    {
        $this->editor();
        $partner = VendorPartner::create(['organization_id' => $this->org->id, 'name' => 'Cisco Systems']);

        $res = $this->postJson($this->url(), [
            'name' => 'Cisco routers', 'vendor_partner_id' => $partner->id,
            'lpo_reference' => 'LPO-4410', 'due_date' => '2026-04-30',
        ])->assertCreated();

        $this->assertMatchesRegularExpression('/^MO-\d+$/', $res->json('data.reference'));
        $this->assertSame('pending', $res->json('data.status'));
        $this->assertSame('Cisco Systems', $res->json('data.supplier.name'));
        $this->assertSame('Cisco Systems', $res->json('data.supplier_name'));
    }

    public function test_it_carries_a_tracker_updates_and_logistics_contacts(): void
    {
        $this->editor();
        $id = $this->order();

        $this->postJson($this->url("/{$id}/trackers"), ['name' => 'LPO issued', 'happened_on' => '2026-03-01'])->assertCreated();
        $this->postJson($this->url("/{$id}/updates"), ['latest_supplier_update' => 'Shipped from Dubai', 'delivery_plan_status' => 'On plan'])->assertCreated();
        $res = $this->postJson($this->url("/{$id}/contacts"), ['name' => 'DHL Nairobi', 'email' => 'ops@dhl.example', 'phone' => '+254700000000'])->assertCreated();

        $this->assertCount(1, $res->json('data.trackers'));
        $this->assertCount(1, $res->json('data.updates'));
        $this->assertCount(1, $res->json('data.contacts'));
        $this->assertSame('DHL Nairobi', $res->json('data.contacts.0.name'));
    }

    public function test_an_empty_delivery_update_is_rejected(): void
    {
        $this->editor();
        $id = $this->order();

        $this->postJson($this->url("/{$id}/updates"), [])->assertStatus(422);
    }

    public function test_a_document_attaches_to_the_order(): void
    {
        $this->editor();
        $id = $this->order();

        $res = $this->postJson($this->url("/{$id}/documents"), [
            'file' => UploadedFile::fake()->create('lpo.pdf', 20, 'application/pdf'),
        ])->assertCreated();

        $this->assertCount(1, $res->json('data.documents'));
    }

    public function test_the_status_moves_through_its_lifecycle(): void
    {
        $this->editor();
        $id = $this->order();

        $this->putJson($this->url("/{$id}"), ['status' => 'in_transit'])
            ->assertOk()->assertJsonPath('data.status', 'in_transit');
    }

    public function test_writing_needs_the_update_permission(): void
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'V', 'scope' => 'organization', 'permissions' => [Permission::PROJECTS_VIEW],
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);

        $this->postJson($this->url(), ['name' => 'x'])->assertForbidden();
    }
}
