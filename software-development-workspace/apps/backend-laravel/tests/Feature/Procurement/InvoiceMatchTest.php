<?php

namespace Tests\Feature\Procurement;

use App\Models\Organization;
use App\Models\Procurement\ProcurementRequest;
use App\Models\Procurement\ProcurementSetting;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Procurement\SupplierInvoice;
use App\Models\Project\Project;
use App\Models\Project\ProjectExpense;
use App\Models\Role;
use App\Models\Training\VendorPartner;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The three-way match, payment, and the closure that locks a purchase. */
class InvoiceMatchTest extends TestCase
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
            'organization_id' => $this->org->id, 'label' => 'Hardware', 'currency' => 'KES', 'amount' => 500_000,
        ]);
        $this->vendor = VendorPartner::create(['organization_id' => $this->org->id, 'name' => 'Alpha Supplies', 'payment_terms_days' => 30]);

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

    /** An approved purchase with an issued, accepted order. */
    private function orderedPurchase(float $amount = 40_000, float $qty = 10): array
    {
        $pr = ProcurementRequest::create([
            'organization_id' => $this->org->id, 'reference' => 'PR-' . fake()->unique()->numberBetween(10000, 99999),
            'context' => 'project', 'project_id' => $this->project->id,
            'item' => 'Patch panels', 'category' => 'hardware', 'quantity' => $qty,
            'estimated_cost' => $amount, 'currency' => 'KES', 'vendor_partner_id' => $this->vendor->id,
        ]);
        $this->postJson($this->url("/{$pr->id}/submit"))->assertOk();
        $this->postJson($this->url("/{$pr->id}/decision"), ['decision' => 'approve'])->assertOk();
        $this->postJson($this->url("/{$pr->id}/orders"), [
            'amount' => $amount, 'quantity_ordered' => $qty, 'expected_delivery_date' => now()->addDays(7)->toDateString(),
        ])->assertCreated();
        $po = PurchaseOrder::where('procurement_request_id', $pr->id)->firstOrFail();
        $this->postJson($this->url("/{$pr->id}/orders/{$po->id}/response"), ['accepted' => true])->assertOk();

        return [$pr->fresh(), $po->fresh()];
    }

    public function test_an_invoice_for_goods_that_arrived_matches_and_is_ready_to_pay(): void
    {
        [$pr, $po] = $this->orderedPurchase();
        $this->postJson($this->url("/{$pr->id}/orders/{$po->id}/receipts"), ['quantity_received' => 10])->assertCreated();

        $res = $this->postJson($this->url("/{$pr->id}/orders/{$po->id}/invoices"), [
            'invoice_number' => 'INV-889', 'amount' => 40_000,
        ])->assertCreated();

        $inv = collect($res->json('data.invoices'))->first();
        $this->assertTrue($inv['match']['po']);
        $this->assertTrue($inv['match']['grn']);
        $this->assertTrue($inv['match']['price']);
        $this->assertSame('matched', $inv['match']['status']);
        $this->assertSame('approved_for_payment', $inv['status']);
        // Due date came from the supplier's 30-day terms.
        $this->assertSame(now()->addDays(30)->toDateString(), $inv['due_date']);
    }

    public function test_an_invoice_before_the_goods_arrive_is_an_exception(): void
    {
        [$pr, $po] = $this->orderedPurchase();

        $res = $this->postJson($this->url("/{$pr->id}/orders/{$po->id}/invoices"), ['amount' => 40_000])->assertCreated();

        $inv = collect($res->json('data.invoices'))->first();
        $this->assertFalse($inv['match']['grn']);
        $this->assertSame('exception', $inv['status']);
        $this->assertStringContainsString('not received in full', $inv['match']['reason']);
    }

    public function test_an_early_invoice_clears_itself_once_the_goods_arrive(): void
    {
        [$pr, $po] = $this->orderedPurchase(40_000, 10);
        // The supplier bills on dispatch, before anything has been received.
        $res = $this->postJson($this->url("/{$pr->id}/orders/{$po->id}/invoices"), ['amount' => 40_000])->assertCreated();
        $invId = collect($res->json('data.invoices'))->first()['id'];
        $this->assertSame('exception', SupplierInvoice::find($invId)->status);

        $this->postJson($this->url("/{$pr->id}/orders/{$po->id}/receipts"), ['quantity_received' => 10])->assertCreated();

        // Nobody had to override anything — the delivery answered the objection.
        $inv = SupplierInvoice::find($invId);
        $this->assertSame('matched', $inv->match_status);
        $this->assertSame(SupplierInvoice::STATUS_APPROVED, $inv->status);
        $this->assertNull($inv->exception_overridden_by);
    }

    public function test_overbilling_beyond_tolerance_is_an_exception(): void
    {
        ProcurementSetting::forOrganization($this->org->id)->update(['price_match_tolerance_percent' => 2]);
        [$pr, $po] = $this->orderedPurchase(40_000, 10);
        $this->postJson($this->url("/{$pr->id}/orders/{$po->id}/receipts"), ['quantity_received' => 10])->assertCreated();

        // 45k against a 40k order is 12.5% over — past the 2% tolerance.
        $res = $this->postJson($this->url("/{$pr->id}/orders/{$po->id}/invoices"), ['amount' => 45_000])->assertCreated();

        $inv = collect($res->json('data.invoices'))->first();
        $this->assertFalse($inv['match']['price']);
        $this->assertStringContainsString('tolerance', $inv['match']['reason']);
    }

    public function test_a_small_overage_inside_tolerance_still_matches(): void
    {
        ProcurementSetting::forOrganization($this->org->id)->update(['price_match_tolerance_percent' => 5]);
        [$pr, $po] = $this->orderedPurchase(40_000, 10);
        $this->postJson($this->url("/{$pr->id}/orders/{$po->id}/receipts"), ['quantity_received' => 10])->assertCreated();

        // 41k is 2.5% over — inside a 5% tolerance.
        $res = $this->postJson($this->url("/{$pr->id}/orders/{$po->id}/invoices"), ['amount' => 41_000])->assertCreated();

        $this->assertTrue(collect($res->json('data.invoices'))->first()['match']['price']);
    }

    public function test_finance_can_override_an_exception_with_a_reason(): void
    {
        [$pr, $po] = $this->orderedPurchase();
        $res = $this->postJson($this->url("/{$pr->id}/orders/{$po->id}/invoices"), ['amount' => 40_000])->assertCreated();
        $invId = collect($res->json('data.invoices'))->first()['id'];

        $this->postJson($this->url("/{$pr->id}/invoices/{$invId}/override"), [
            'justification' => 'Goods verified on site by the engineer',
        ])->assertOk();

        $inv = SupplierInvoice::find($invId);
        $this->assertSame(SupplierInvoice::STATUS_APPROVED, $inv->status);
        $this->assertNotNull($inv->exception_overridden_by);
    }

    public function test_paying_posts_the_expense_once_and_replaces_the_commitment(): void
    {
        [$pr, $po] = $this->orderedPurchase(40_000, 10);
        $this->postJson($this->url("/{$pr->id}/orders/{$po->id}/receipts"), ['quantity_received' => 10])->assertCreated();
        $res = $this->postJson($this->url("/{$pr->id}/orders/{$po->id}/invoices"), ['amount' => 40_000])->assertCreated();
        $invId = collect($res->json('data.invoices'))->first()['id'];

        $budget = app(\App\Services\Procurement\ProcurementBudgetService::class);
        $this->assertSame(40000.0, $budget->committed($this->project, 'KES'));   // promised

        $this->postJson($this->url("/{$pr->id}/invoices/{$invId}/payment"), [
            'payment_reference' => 'EFT-77120',
        ])->assertOk();

        // Spent, and no longer committed — never both.
        $this->assertSame(40000.0, (float) ProjectExpense::where('project_id', $this->project->id)->sum('amount'));
        $this->assertSame(0.0, $budget->committed($this->project, 'KES'));
        $this->assertSame('paid', $pr->fresh()->lifecycle_stage);

        // Paying again cannot charge the project twice.
        $this->postJson($this->url("/{$pr->id}/invoices/{$invId}/payment"))->assertOk();
        $this->assertSame(1, ProjectExpense::where('project_id', $this->project->id)->count());
        $this->assertSame(40000.0, (float) ProjectExpense::where('project_id', $this->project->id)->sum('amount'));
    }

    public function test_an_unmatched_invoice_cannot_be_paid(): void
    {
        [$pr, $po] = $this->orderedPurchase();
        $res = $this->postJson($this->url("/{$pr->id}/orders/{$po->id}/invoices"), ['amount' => 40_000])->assertCreated();
        $invId = collect($res->json('data.invoices'))->first()['id'];

        $this->postJson($this->url("/{$pr->id}/invoices/{$invId}/payment"))->assertStatus(422);
    }

    public function test_closure_needs_the_goods_in_and_the_bills_paid_then_locks(): void
    {
        [$pr, $po] = $this->orderedPurchase(40_000, 10);

        // Nothing received yet.
        $this->postJson($this->url("/{$pr->id}/close"))->assertStatus(422);

        $this->postJson($this->url("/{$pr->id}/orders/{$po->id}/receipts"), ['quantity_received' => 10])->assertCreated();
        // Received, but nothing invoiced or paid.
        $this->postJson($this->url("/{$pr->id}/close"))->assertStatus(422);

        $res = $this->postJson($this->url("/{$pr->id}/orders/{$po->id}/invoices"), ['amount' => 40_000])->assertCreated();
        $invId = collect($res->json('data.invoices'))->first()['id'];
        // Invoiced but unpaid.
        $this->postJson($this->url("/{$pr->id}/close"))->assertStatus(422);

        $this->postJson($this->url("/{$pr->id}/invoices/{$invId}/payment"))->assertOk();
        $this->postJson($this->url("/{$pr->id}/close"))->assertOk();

        $pr->refresh();
        $this->assertSame('closed', $pr->lifecycle_stage);
        $this->assertNotNull($pr->closed_at);
        $this->assertSame(PurchaseOrder::STATUS_CLOSED, $po->fresh()->status);

        // Locked: nothing more happens to a closed purchase. Editing what was
        // bought, or deleting it, would rewrite the evidence behind money that
        // has already left the building.
        $this->postJson($this->url("/{$pr->id}/decision"), ['decision' => 'approve'])->assertStatus(422);
        $this->postJson($this->url("/{$pr->id}/orders"), ['amount' => 1000])->assertStatus(422);
        $this->putJson($this->url("/{$pr->id}"), ['item' => 'Something else entirely'])->assertStatus(422);
        $this->deleteJson($this->url("/{$pr->id}"))->assertStatus(422);
        $this->postJson($this->url("/{$pr->id}/orders/{$po->id}/invoices"), ['amount' => 1000])->assertStatus(422);

        $this->assertSame('Patch panels', $pr->fresh()->item);
        $this->assertNotNull(ProcurementRequest::find($pr->id));
    }
}
