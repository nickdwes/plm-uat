<?php

namespace Tests\Feature\Procurement;

use App\Models\Organization;
use App\Models\Procurement\ProcurementRequest;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Project\Project;
use App\Models\Project\ProjectMaterialOrder;
use App\Models\Role;
use App\Models\Training\VendorPartner;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Placing the order with a supplier, and receiving against it. */
class PurchaseOrderTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Project $project;
    private VendorPartner $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
        $this->project = Project::create(['organization_id' => $this->org->id, 'name' => 'SD-WAN']);
        $this->project->budgetLines()->create([
            'organization_id' => $this->org->id, 'label' => 'Hardware', 'currency' => 'KES', 'amount' => 5_000_000,
        ]);
        $this->vendor = VendorPartner::create(['organization_id' => $this->org->id, 'name' => 'Alpha Supplies']);

        \App\Models\Procurement\ProcurementApprovalStage::create([
            'organization_id' => $this->org->id, 'stage' => 'department_head', 'category' => null,
            'sequence' => 1, 'required_above_amount' => null, 'currency' => 'KES', 'is_active' => true,
        ]);

        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'T', 'scope' => 'organization',
            'permissions' => [
                Permission::ASSETS_VIEW, Permission::ASSETS_CREATE, Permission::ASSETS_UPDATE,
                Permission::PROJECTS_VIEW, Permission::PROJECTS_UPDATE,
                Permission::PROCUREMENT_APPROVE_DEPARTMENT, Permission::PROCUREMENT_APPROVE_FINANCE,
            ],
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/procurement{$path}?org={$this->org->slug}";
    }

    /** An approved purchase with a named supplier, ready to order. */
    private function approved(float $amount = 40_000, float $qty = 10): ProcurementRequest
    {
        $pr = ProcurementRequest::create([
            'organization_id' => $this->org->id, 'reference' => 'PR-' . fake()->unique()->numberBetween(10000, 99999),
            'context' => 'project', 'project_id' => $this->project->id,
            'item' => 'Patch panels', 'category' => 'hardware', 'quantity' => $qty,
            'estimated_cost' => $amount, 'currency' => 'KES',
            'vendor_partner_id' => $this->vendor->id, 'required_by' => now()->addDays(14)->toDateString(),
        ]);
        $this->postJson($this->url("/{$pr->id}/submit"))->assertOk();
        $this->postJson($this->url("/{$pr->id}/decision"), ['decision' => 'approve'])->assertOk();

        return $pr->fresh();
    }

    public function test_issuing_an_order_computes_tax_and_moves_the_purchase_on(): void
    {
        $pr = $this->approved();

        $res = $this->postJson($this->url("/{$pr->id}/orders"), [
            'amount' => 40_000, 'tax_rate' => 16, 'payment_terms' => '30 days',
            'expected_delivery_date' => now()->addDays(10)->toDateString(),
        ])->assertCreated();

        $po = collect($res->json('data.orders'))->first();
        $this->assertSame(40000.0, (float) $po['amount']);
        $this->assertSame(6400.0, (float) $po['tax_amount']);      // 16% of 40k
        $this->assertSame(46400.0, (float) $po['total_amount']);
        $this->assertSame('issued', $po['status']);
        $this->assertSame('po_issued', $pr->fresh()->lifecycle_stage);
    }

    public function test_a_purchase_may_only_have_one_live_order(): void
    {
        $pr = $this->approved();
        $this->postJson($this->url("/{$pr->id}/orders"), ['amount' => 40_000])->assertCreated();
        $this->postJson($this->url("/{$pr->id}/orders"), ['amount' => 40_000])->assertStatus(422);

        // Cancelling frees it for a replacement.
        $poId = PurchaseOrder::where('procurement_request_id', $pr->id)->value('id');
        $this->postJson($this->url("/{$pr->id}/orders/{$poId}/cancel"), ['reason' => 'Wrong spec'])->assertOk();
        $this->postJson($this->url("/{$pr->id}/orders"), ['amount' => 40_000])->assertCreated();
    }

    public function test_an_order_cannot_be_placed_before_approval(): void
    {
        $pr = ProcurementRequest::create([
            'organization_id' => $this->org->id, 'reference' => 'PR-X', 'context' => 'project',
            'project_id' => $this->project->id, 'item' => 'Panels', 'quantity' => 5,
            'estimated_cost' => 20_000, 'currency' => 'KES', 'vendor_partner_id' => $this->vendor->id,
        ]);

        $this->postJson($this->url("/{$pr->id}/orders"), ['amount' => 20_000])->assertStatus(422);
    }

    public function test_the_supplier_declining_frees_the_purchase_for_another_order(): void
    {
        $pr = $this->approved();
        $this->postJson($this->url("/{$pr->id}/orders"), ['amount' => 40_000])->assertCreated();
        $poId = PurchaseOrder::where('procurement_request_id', $pr->id)->value('id');

        $this->postJson($this->url("/{$pr->id}/orders/{$poId}/response"), ['accepted' => false, 'note' => 'Out of stock'])->assertOk();

        $this->assertSame('vendor_declined', PurchaseOrder::find($poId)->status);
        $this->assertSame('awarded', $pr->fresh()->lifecycle_stage);
        $this->postJson($this->url("/{$pr->id}/orders"), ['amount' => 40_000])->assertCreated();
    }

    public function test_partial_deliveries_add_up_before_the_order_is_received(): void
    {
        $pr = $this->approved(40_000, 10);
        $this->postJson($this->url("/{$pr->id}/orders"), [
            'amount' => 40_000, 'quantity_ordered' => 10, 'expected_delivery_date' => now()->addDays(5)->toDateString(),
        ])->assertCreated();
        $poId = PurchaseOrder::where('procurement_request_id', $pr->id)->value('id');
        $this->postJson($this->url("/{$pr->id}/orders/{$poId}/response"), ['accepted' => true])->assertOk();

        // Six of ten arrive.
        $res = $this->postJson($this->url("/{$pr->id}/orders/{$poId}/receipts"), ['quantity_received' => 6])->assertCreated();
        $po = collect($res->json('data.orders'))->first();
        $this->assertSame('partially_received', $po['status']);
        $this->assertSame(4.0, (float) $po['outstanding']);
        $this->assertSame(4.0, (float) $po['receipts'][0]['variance']);   // still outstanding
        $this->assertTrue($po['receipts'][0]['on_time']);
        $this->assertSame('delivering', $pr->fresh()->lifecycle_stage);

        // The last four complete it.
        $res = $this->postJson($this->url("/{$pr->id}/orders/{$poId}/receipts"), ['quantity_received' => 4])->assertCreated();
        $po = collect($res->json('data.orders'))->first();
        $this->assertSame('received', $po['status']);
        $this->assertSame(0.0, (float) $po['outstanding']);
        $this->assertSame('received', $pr->fresh()->lifecycle_stage);
    }

    public function test_a_late_delivery_is_stamped_late_at_receipt(): void
    {
        $pr = $this->approved();
        $this->postJson($this->url("/{$pr->id}/orders"), [
            'amount' => 40_000, 'quantity_ordered' => 5,
            'expected_delivery_date' => now()->subDays(3)->toDateString(),
        ])->assertCreated();
        $poId = PurchaseOrder::where('procurement_request_id', $pr->id)->value('id');

        $res = $this->postJson($this->url("/{$pr->id}/orders/{$poId}/receipts"), ['quantity_received' => 5])->assertCreated();

        $this->assertFalse(collect($res->json('data.orders'))->first()['receipts'][0]['on_time']);
    }

    public function test_the_material_order_register_mirrors_the_purchase_order(): void
    {
        $pr = $this->approved(40_000, 4);
        // The older supplier-order register already has a matching row.
        $mo = ProjectMaterialOrder::create([
            'organization_id' => $this->org->id, 'project_id' => $this->project->id,
            'reference' => 'MO-1', 'name' => 'Patch panels', 'status' => ProjectMaterialOrder::STATUS_PENDING,
        ]);

        $this->postJson($this->url("/{$pr->id}/orders"), ['amount' => 40_000, 'quantity_ordered' => 4])->assertCreated();
        $po = PurchaseOrder::where('procurement_request_id', $pr->id)->first();

        // Bridged, and carrying the real PO reference.
        $mo->refresh();
        $this->assertSame($mo->id, $po->material_order_id);
        $this->assertSame(ProjectMaterialOrder::STATUS_ORDERED, $mo->status);
        $this->assertSame($po->reference, $mo->lpo_reference);

        $this->postJson($this->url("/{$pr->id}/orders/{$po->id}/receipts"), ['quantity_received' => 4])->assertCreated();
        $this->assertSame(ProjectMaterialOrder::STATUS_DELIVERED, $mo->fresh()->status);
    }
}
