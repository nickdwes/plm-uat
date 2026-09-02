<?php

namespace Tests\Feature\Procurement;

use App\Models\Organization;
use App\Models\Procurement\ProcurementRequest;
use App\Models\Procurement\Rfq;
use App\Models\Project\Project;
use App\Models\Role;
use App\Models\Training\VendorPartner;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Sourcing: the three-quote rule, scoring, and the award. */
class RfqAwardTest extends TestCase
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
        $this->project->budgetLines()->create([
            'organization_id' => $this->org->id, 'label' => 'Hardware', 'currency' => 'KES', 'amount' => 5_000_000,
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

    private function vendor(string $name): VendorPartner
    {
        return VendorPartner::create(['organization_id' => $this->org->id, 'name' => $name]);
    }

    /** An approved purchase, ready to source. */
    private function approvedRequest(float $amount = 400_000): ProcurementRequest
    {
        $pr = ProcurementRequest::create([
            'organization_id' => $this->org->id, 'reference' => 'PR-' . fake()->unique()->numberBetween(10000, 99999),
            'context' => 'project', 'project_id' => $this->project->id,
            'item' => 'Core switches', 'category' => 'hardware', 'quantity' => 3,
            'estimated_cost' => $amount, 'currency' => 'KES',
        ]);
        $this->postJson($this->url("/{$pr->id}/submit"))->assertOk();
        $this->postJson($this->url("/{$pr->id}/decision"), ['decision' => 'approve'])->assertOk();

        return $pr->fresh();
    }

    public function test_sourcing_requires_an_approved_purchase(): void
    {
        $pr = ProcurementRequest::create([
            'organization_id' => $this->org->id, 'reference' => 'PR-X', 'context' => 'project',
            'project_id' => $this->project->id, 'item' => 'Switch', 'estimated_cost' => 100_000, 'currency' => 'KES',
        ]);

        $this->postJson($this->url("/{$pr->id}/rfq"))->assertStatus(422);
    }

    public function test_quotes_are_scored_relative_to_the_best_offer(): void
    {
        $pr = $this->approvedRequest();
        $this->postJson($this->url("/{$pr->id}/rfq"))->assertCreated();

        // Cheapest and fastest should set the bar.
        $this->postJson($this->url("/{$pr->id}/rfq/quotations"), [
            'vendor_partner_id' => $this->vendor('Alpha Supplies')->id, 'amount' => 400_000, 'lead_time_days' => 20,
        ])->assertCreated();
        $res = $this->postJson($this->url("/{$pr->id}/rfq/quotations"), [
            'vendor_partner_id' => $this->vendor('Beta Traders')->id, 'amount' => 500_000, 'lead_time_days' => 10,
        ])->assertCreated();

        $quotes = collect($res->json('data.quotations'))->keyBy('vendor');
        // Alpha is cheapest → full price marks; Beta is 400/500 of it.
        $this->assertSame(100.0, (float) $quotes['Alpha Supplies']['price_score']);
        $this->assertSame(80.0, (float) $quotes['Beta Traders']['price_score']);
        // Beta is fastest → full delivery marks; Alpha is 10/20 of it.
        $this->assertSame(100.0, (float) $quotes['Beta Traders']['delivery_score']);
        $this->assertSame(50.0, (float) $quotes['Alpha Supplies']['delivery_score']);
    }

    public function test_an_award_needs_three_quotes(): void
    {
        $pr = $this->approvedRequest();
        $this->postJson($this->url("/{$pr->id}/rfq"))->assertCreated();

        $first = $this->vendor('Alpha Supplies');
        $this->postJson($this->url("/{$pr->id}/rfq/quotations"), ['vendor_partner_id' => $first->id, 'amount' => 400_000])->assertCreated();
        $this->postJson($this->url("/{$pr->id}/rfq/quotations"), ['vendor_partner_id' => $this->vendor('Beta')->id, 'amount' => 450_000])->assertCreated();

        $quoteId = collect($this->getJson($this->url("/{$pr->id}/rfq"))->json('data.quotations'))
            ->firstWhere('vendor', 'Alpha Supplies')['id'];

        // Two of three — refused, and the message says so.
        $this->postJson($this->url("/{$pr->id}/rfq/award"), ['quotation_id' => $quoteId])->assertStatus(422);

        $this->postJson($this->url("/{$pr->id}/rfq/quotations"), ['vendor_partner_id' => $this->vendor('Gamma')->id, 'amount' => 470_000])->assertCreated();
        $this->assertTrue($this->getJson($this->url("/{$pr->id}/rfq"))->json('data.rfq.compliance_met'));
        $this->postJson($this->url("/{$pr->id}/rfq/award"), ['quotation_id' => $quoteId])->assertOk();

        $pr->refresh();
        $this->assertSame($first->id, $pr->awarded_vendor_id);
        $this->assertSame(400000.0, (float) $pr->awarded_amount);
        $this->assertSame('awarded', $pr->lifecycle_stage);
    }

    public function test_a_waiver_lets_a_single_source_award_proceed_on_the_record(): void
    {
        $pr = $this->approvedRequest();
        $this->postJson($this->url("/{$pr->id}/rfq"))->assertCreated();
        $only = $this->vendor('Sole OEM');
        $this->postJson($this->url("/{$pr->id}/rfq/quotations"), ['vendor_partner_id' => $only->id, 'amount' => 380_000])->assertCreated();

        $quoteId = $this->getJson($this->url("/{$pr->id}/rfq"))->json('data.quotations.0.id');
        $this->postJson($this->url("/{$pr->id}/rfq/award"), ['quotation_id' => $quoteId])->assertStatus(422);

        $this->postJson($this->url("/{$pr->id}/rfq/waive"), ['reason' => 'Sole authorised OEM distributor'])->assertOk();
        $this->postJson($this->url("/{$pr->id}/rfq/award"), ['quotation_id' => $quoteId])->assertOk();

        $rfq = Rfq::where('procurement_request_id', $pr->id)->first();
        $this->assertTrue($rfq->compliance_met);
        $this->assertStringContainsString('Sole authorised', $rfq->waiver_reason);
        $this->assertNotNull($rfq->waived_by);
    }

    public function test_choosing_a_lower_scoring_quote_must_be_justified(): void
    {
        $pr = $this->approvedRequest();
        $this->postJson($this->url("/{$pr->id}/rfq"))->assertCreated();
        foreach ([['Cheap Co', 300_000], ['Mid Co', 400_000], ['Costly Co', 600_000]] as [$name, $amount]) {
            $this->postJson($this->url("/{$pr->id}/rfq/quotations"), ['vendor_partner_id' => $this->vendor($name)->id, 'amount' => $amount])->assertCreated();
        }

        $quotes = collect($this->getJson($this->url("/{$pr->id}/rfq"))->json('data.quotations'));
        $this->assertTrue($quotes->firstWhere('vendor', 'Cheap Co')['recommended']);

        $costly = $quotes->firstWhere('vendor', 'Costly Co')['id'];
        $this->postJson($this->url("/{$pr->id}/rfq/award"), ['quotation_id' => $costly])->assertStatus(422);

        $this->postJson($this->url("/{$pr->id}/rfq/award"), [
            'quotation_id' => $costly, 'justification' => 'Only vendor certified for the platform',
        ])->assertOk();

        $this->assertSame(600000.0, (float) $pr->fresh()->awarded_amount);
    }

    public function test_the_award_price_becomes_the_commitment(): void
    {
        $pr = $this->approvedRequest(400_000);
        $this->postJson($this->url("/{$pr->id}/rfq"))->assertCreated();
        foreach ([['A', 250_000], ['B', 300_000], ['C', 320_000]] as [$name, $amount]) {
            $this->postJson($this->url("/{$pr->id}/rfq/quotations"), ['vendor_partner_id' => $this->vendor($name)->id, 'amount' => $amount])->assertCreated();
        }
        $quoteId = $this->getJson($this->url("/{$pr->id}/rfq"))->json('data.quotations.0.id');
        $this->postJson($this->url("/{$pr->id}/rfq/award"), ['quotation_id' => $quoteId])->assertOk();

        // Committed on the awarded 250k, not the 400k estimate.
        $committed = app(\App\Services\Procurement\ProcurementBudgetService::class)
            ->committed($this->project, 'KES');
        $this->assertSame(250000.0, $committed);
    }
}
