<?php

namespace Tests\Feature\Procurement;

use App\Models\Organization;
use App\Models\Procurement\ProcurementRequest;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Project\Project;
use App\Models\Role;
use App\Models\Training\VendorPartner;
use App\Models\User;
use App\Services\Procurement\VendorPerformanceService;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Scorecards, dashboard metrics, and the lifecycle timeline. */
class ProcurementAnalyticsTest extends TestCase
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
        $this->project = Project::create(['organization_id' => $this->org->id, 'name' => 'Rollout']);
        $this->project->budgetLines()->create([
            'organization_id' => $this->org->id, 'label' => 'Kit', 'currency' => 'KES', 'amount' => 2_000_000,
        ]);
        $this->vendor = VendorPartner::create([
            'organization_id' => $this->org->id, 'name' => 'Alpha Supplies', 'payment_terms_days' => 30,
        ]);

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
                // The vendor scorecard used to answer to `assets.view`; it now
                // asks for the vendor permission it was always about.
                Permission::VENDORS_VIEW,
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

    /**
     * A whole purchase driven end to end through the API, so the metrics are
     * measured against records the lifecycle actually produced.
     */
    private function completePurchase(float $estimate, float $ordered, string $quality = 'accepted', int $dueInDays = 7): ProcurementRequest
    {
        $pr = ProcurementRequest::create([
            'organization_id' => $this->org->id, 'reference' => 'PR-' . fake()->unique()->numberBetween(10000, 99999),
            'context' => 'project', 'project_id' => $this->project->id,
            'item' => 'Switches', 'category' => 'hardware', 'quantity' => 5,
            'estimated_cost' => $estimate, 'currency' => 'KES', 'vendor_partner_id' => $this->vendor->id,
        ]);
        $this->postJson($this->url("/{$pr->id}/submit"))->assertOk();
        $this->postJson($this->url("/{$pr->id}/decision"), ['decision' => 'approve'])->assertOk();
        $pr->update(['awarded_amount' => $ordered, 'awarded_vendor_id' => $this->vendor->id]);

        $this->postJson($this->url("/{$pr->id}/orders"), [
            'amount' => $ordered, 'quantity_ordered' => 5,
            'expected_delivery_date' => now()->addDays($dueInDays)->toDateString(),
        ])->assertCreated();
        $po = PurchaseOrder::where('procurement_request_id', $pr->id)->firstOrFail();
        $this->postJson($this->url("/{$pr->id}/orders/{$po->id}/response"), ['accepted' => true])->assertOk();
        $this->postJson($this->url("/{$pr->id}/orders/{$po->id}/receipts"), [
            'quantity_received' => 5, 'quality_status' => $quality,
        ])->assertCreated();

        $res = $this->postJson($this->url("/{$pr->id}/orders/{$po->id}/invoices"), ['amount' => $ordered])->assertCreated();
        $invId = collect($res->json('data.invoices'))->first()['id'];
        $this->postJson($this->url("/{$pr->id}/invoices/{$invId}/payment"), ['payment_reference' => 'EFT-1'])->assertOk();

        return $pr->fresh();
    }

    public function test_a_vendor_nobody_has_bought_from_scores_null_not_zero(): void
    {
        $card = app(VendorPerformanceService::class)->scorecard($this->vendor);

        $this->assertNull($card['delivery']['on_time_rate']);
        $this->assertNull($card['quality']['acceptance_rate']);
        $this->assertNull($card['overall']);
        $this->assertNull($card['rating']);
        $this->assertSame(0, $card['volume']['orders']);
    }

    public function test_the_scorecard_reads_delivery_and_quality_off_the_receipts(): void
    {
        $this->completePurchase(100_000, 95_000, 'accepted');
        $this->completePurchase(60_000, 60_000, 'partial');

        $card = app(VendorPerformanceService::class)->scorecard($this->vendor->fresh());

        $this->assertSame(2, $card['volume']['orders']);
        // Both arrived inside the promised window.
        $this->assertSame(100.0, $card['delivery']['on_time_rate']);
        // One accepted outright, one partial — a partial counts as half.
        $this->assertSame(75.0, $card['quality']['acceptance_rate']);
        $this->assertSame(155000.0, $card['volume']['spend']);
        $this->assertNotNull($card['overall']);
        $this->assertContains($card['rating'], ['excellent', 'good', 'fair', 'poor']);
    }

    public function test_a_late_delivery_shows_up_against_the_supplier(): void
    {
        // Due yesterday, delivered today.
        $this->completePurchase(50_000, 50_000, 'accepted', dueInDays: -1);

        $card = app(VendorPerformanceService::class)->scorecard($this->vendor->fresh());

        $this->assertSame(0.0, $card['delivery']['on_time_rate']);
        $this->assertSame(1, $card['delivery']['late_count']);
    }

    public function test_a_competitive_quote_alone_does_not_earn_a_rating(): void
    {
        // A supplier who bid well and lost has a price score and nothing else.
        // Rating them off that alone would put a vendor who has never shipped
        // anything above one with a real delivery record.
        $bidder = VendorPartner::create(['organization_id' => $this->org->id, 'name' => 'Only Ever Quoted Ltd']);
        $pr = ProcurementRequest::create([
            'organization_id' => $this->org->id, 'reference' => 'PR-' . fake()->unique()->numberBetween(10000, 99999),
            'context' => 'project', 'project_id' => $this->project->id,
            'item' => 'Cable', 'category' => 'hardware', 'quantity' => 1,
            'estimated_cost' => 90_000, 'currency' => 'KES',
        ]);
        $this->postJson($this->url("/{$pr->id}/submit"))->assertOk();
        $this->postJson($this->url("/{$pr->id}/decision"), ['decision' => 'approve'])->assertOk();
        $this->postJson($this->url("/{$pr->id}/rfq"))->assertCreated();
        $this->postJson($this->url("/{$pr->id}/rfq/quotations"), ['vendor_partner_id' => $bidder->id, 'amount' => 80_000])->assertCreated();
        $this->postJson($this->url("/{$pr->id}/rfq/quotations"), ['vendor_partner_id' => $this->vendor->id, 'amount' => 95_000])->assertCreated();

        $card = app(VendorPerformanceService::class)->scorecard($bidder->fresh());

        $this->assertNotNull($card['commercial']['price_competitiveness']);
        $this->assertSame(0, $card['volume']['deliveries']);
        $this->assertNull($card['overall']);
        $this->assertNull($card['rating']);
    }

    public function test_the_dashboard_reports_spend_savings_and_pipeline(): void
    {
        $this->completePurchase(100_000, 90_000);
        $this->completePurchase(50_000, 50_000);

        $d = $this->getJson($this->url('/dashboard'))->assertOk()->json('data');

        $this->assertEquals(150000, $d['spend']['requested']);
        $this->assertEquals(140000, $d['spend']['paid']);
        // Both purchases are paid, so nothing is still promised.
        $this->assertEquals(0, $d['spend']['committed']);
        // Sourcing brought 150k of estimates in at 140k.
        $this->assertSame(2, $d['savings']['awarded_count']);
        $this->assertEquals(10000, $d['savings']['saved']);
        $this->assertSame(2, $d['pipeline']['by_stage']['paid']);
        $this->assertSame(0, $d['pipeline']['awaiting_approval']);
    }

    public function test_savings_tell_the_truth_when_the_estimate_was_optimistic(): void
    {
        // Awarded above the estimate — a negative saving is a real answer.
        $this->completePurchase(40_000, 55_000);

        $d = $this->getJson($this->url('/dashboard'))->assertOk()->json('data');

        $this->assertEquals(-15000, $d['savings']['saved']);
    }

    public function test_cycle_times_are_measured_between_the_lifecycle_stamps(): void
    {
        $this->completePurchase(80_000, 80_000);

        $c = $this->getJson($this->url('/dashboard'))->assertOk()->json('data.cycle');

        // Everything happened in one test run, so each leg is same-day — the
        // point is that a number is produced rather than a null.
        $this->assertNotNull($c['submit_to_approve']);
        $this->assertNotNull($c['order_to_delivery']);
        $this->assertNotNull($c['invoice_to_payment']);
        $this->assertNull($c['end_to_end']);   // nothing has been closed
    }

    public function test_the_dashboard_narrows_to_a_project(): void
    {
        $this->completePurchase(100_000, 100_000);
        $other = Project::create(['organization_id' => $this->org->id, 'name' => 'Elsewhere']);

        $d = $this->getJson($this->url('/dashboard') . "&project_id={$other->id}")->assertOk()->json('data');

        $this->assertEquals(0, $d['spend']['requested']);
        $this->assertSame(0, $d['savings']['awarded_count']);
    }

    public function test_the_timeline_tells_the_story_of_one_purchase(): void
    {
        $pr = $this->completePurchase(70_000, 70_000);
        $this->postJson($this->url("/{$pr->id}/close"))->assertOk();

        $t = $this->getJson($this->url("/{$pr->id}/timeline"))->assertOk()->json('data');

        $kinds = collect($t['events'])->pluck('kind')->all();
        foreach (['raised', 'submitted', 'approved', 'po_issued', 'po_accepted', 'received', 'invoiced', 'paid', 'closed'] as $expected) {
            $this->assertContains($expected, $kinds, "the timeline is missing '{$expected}'");
        }

        // In order, oldest first.
        $times = collect($t['events'])->pluck('at')->all();
        $sorted = $times;
        sort($sorted);
        $this->assertSame($sorted, $times);
        $this->assertSame('closed', $t['stage']);
    }

    public function test_the_scorecard_endpoint_serves_one_vendor(): void
    {
        $this->completePurchase(100_000, 100_000);

        $d = $this->getJson($this->url("/vendors/{$this->vendor->id}/scorecard"))->assertOk()->json('data');

        $this->assertSame($this->vendor->id, $d['vendor']['id']);
        $this->assertSame(1, $d['volume']['orders']);
        $this->assertNotNull($d['overall']);
    }

    public function test_the_ranking_puts_the_better_supplier_first(): void
    {
        $laggard = VendorPartner::create(['organization_id' => $this->org->id, 'name' => 'Zeta Late Ltd']);

        $this->completePurchase(100_000, 100_000, 'accepted');           // Alpha: on time, accepted
        $this->vendor = $laggard;
        $this->completePurchase(100_000, 100_000, 'rejected', -3);       // Zeta: late, rejected

        $ranking = app(VendorPerformanceService::class)->ranking($this->org->id);

        $this->assertSame('Alpha Supplies', $ranking[0]['vendor']);
        $this->assertSame('Zeta Late Ltd', $ranking[1]['vendor']);
        $this->assertGreaterThan($ranking[1]['overall'], $ranking[0]['overall']);
    }
}
