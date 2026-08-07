<?php

namespace Tests\Feature\Pipeline;

use App\Models\Account\Account;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Pipeline\Opportunity;
use App\Models\Project\Project;
use App\Models\Role;
use App\Models\Training\VendorPartner;
use App\Models\User;
use App\Support\Account\DefaultReferenceData;
use App\Support\Authorization\Permission;
use App\Support\Pipeline\DeliverySplit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * How much of the delivery we take, and how much goes to the OEM.
 *
 * Replaces the legacy's `ps_ratio` — blank on 63% of its 70 projects, and where
 * present, ambiguous: `100` gave no way to tell whether it meant all ours or
 * all theirs, and `5` and `95` are the same decision written from opposite
 * ends. One row read "Nairobi".
 *
 * Both sides are named here, so the share is derived and cannot be ambiguous.
 */
class DeliverySplitTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Account $account;
    private Opportunity $deal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
        DefaultReferenceData::seedFor($this->org->id);

        $this->account = Account::create([
            'organization_id' => $this->org->id, 'name' => 'Kenya Commercial Bank',
        ]);

        $this->deal = Opportunity::create([
            'organization_id' => $this->org->id, 'account_id' => $this->account->id,
            'name' => 'Integrate AI lead scoring module',
        ]);
    }

    private function actor(array $permissions): Employee
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Test', 'scope' => 'organization', 'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();

        $employee = Employee::create([
            'employee_number'         => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id,
            'first_name' => 'Test', 'last_name' => 'Person',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
            'user_id' => $user->id,
        ]);

        Sanctum::actingAs($user);

        return $employee->fresh();
    }

    private function oem(): VendorPartner
    {
        return VendorPartner::create([
            'organization_id' => $this->org->id, 'name' => 'Cisco', 'is_active' => true,
        ]);
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/opportunities/{$this->deal->id}{$path}?org={$this->org->slug}";
    }

    // ── The share itself ──────────────────────────────────────────────────

    public function test_the_share_is_derived_from_two_named_quantities(): void
    {
        // No `100` that could mean either side.
        $this->assertSame(20, DeliverySplit::oemShare(80, 20));
        $this->assertSame(80, DeliverySplit::oemShare(20, 80));
        $this->assertSame(100, DeliverySplit::oemShare(0, 40));
        $this->assertSame(0, DeliverySplit::oemShare(40, 0));
    }

    public function test_no_allocation_is_not_the_same_as_allocating_none(): void
    {
        /*
         * Null, not zero. "Nobody has said" and "the OEM does none of it" are
         * different claims, and only one of them says we are doing all the
         * work — which is exactly the assumption the legacy's blank field
         * silently invited.
         */
        $this->assertNull(DeliverySplit::oemShare(null, null));
        $this->assertSame(0, DeliverySplit::oemShare(40, null));
    }

    public function test_zero_total_days_yields_no_share_rather_than_a_division_by_zero(): void
    {
        $this->assertNull(DeliverySplit::oemShare(0, 0));
    }

    // ── On an estimate ────────────────────────────────────────────────────

    public function test_an_estimate_records_the_allocation_against_a_named_oem(): void
    {
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE, Permission::PIPELINE_VIEW_COMMERCIALS]);
        $oem = $this->oem();

        $res = $this->postJson($this->url('/estimates'), [
            'name' => 'Phase 1 — Network', 'revenue' => 30000, 'cost' => 20000, 'profit' => 10000,
            'vendor_partner_id' => $oem->id,
            'delivery_days_own' => 34, 'delivery_days_oem' => 16, 'oem_ps_cost' => 8000,
        ])->assertOk();

        $estimate = $res->json('data.estimates.0');

        $this->assertSame('Cisco', $estimate['vendor_partner']['name']);
        $this->assertSame(32, $estimate['oem_share']);
        $this->assertEquals(50, $estimate['total_delivery_days']);
        $this->assertEquals(8000, $estimate['oem_ps_cost']);
    }

    public function test_days_are_visible_without_the_commercial_elevation_but_the_oem_cost_is_not(): void
    {
        /*
         * How many people a job needs is a resourcing fact, not a price. What
         * the OEM charges us is money, and is masked like everything else.
         */
        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE, Permission::PIPELINE_VIEW_COMMERCIALS]);
        $this->postJson($this->url('/estimates'), [
            'name' => 'Phase 1', 'delivery_days_own' => 34, 'delivery_days_oem' => 16, 'oem_ps_cost' => 8000,
        ])->assertOk();

        $this->actor([Permission::PIPELINE_VIEW]);
        $estimate = $this->getJson($this->url('/pipeline'))->assertOk()->json('data.estimates.0');

        $this->assertEquals(34, $estimate['delivery_days_own']);
        $this->assertSame(32, $estimate['oem_share']);
        $this->assertNull($estimate['oem_ps_cost']);
    }

    public function test_the_oem_must_be_a_partner_of_this_organisation(): void
    {
        $beta = Organization::create(['name' => 'Beta', 'slug' => 'beta']);
        $theirs = VendorPartner::withoutGlobalScopes()->create([
            'organization_id' => $beta->id, 'name' => 'Theirs', 'is_active' => true,
        ]);

        $this->actor([Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE]);

        $this->postJson($this->url('/estimates'), [
            'name' => 'Phase 1', 'vendor_partner_id' => $theirs->id,
        ])->assertStatus(422)->assertJsonValidationErrors('vendor_partner_id');
    }

    // ── Planned against delivered ─────────────────────────────────────────

    public function test_a_project_records_what_was_delivered_separately_from_what_was_planned(): void
    {
        /*
         * The gap between them is the point. Overwriting the estimate with the
         * project would destroy the only measurement worth having.
         */
        $this->actor([
            Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE,
            Permission::PROJECTS_VIEW, Permission::PROJECTS_CREATE,
        ]);

        // Scoped at 20% OEM.
        $this->postJson($this->url('/estimates'), [
            'name' => 'Phase 1', 'delivery_days_own' => 80, 'delivery_days_oem' => 20,
        ])->assertOk();

        // Delivered at 60%.
        $res = $this->postJson("/api/v1/projects?org={$this->org->slug}", [
            'name' => 'Employee Portal Revamp', 'opportunity_id' => $this->deal->id,
            'delivery_days_own' => 40, 'delivery_days_oem' => 60,
        ])->assertCreated();

        $this->assertSame(60, $res->json('data.delivery.oem_share'));
        // 60 delivered against 20 planned — either it was mis-scoped or people
        // were lost, and both are worth knowing before the next quote.
        $this->assertSame(40, $res->json('data.delivery.oem_share_drift'));
    }

    public function test_drift_is_null_until_there_is_something_to_drift_from(): void
    {
        // A project with no estimate behind it has no plan to compare against,
        // and reporting 0 there would read as "delivered exactly as scoped".
        $this->actor([Permission::PROJECTS_VIEW, Permission::PROJECTS_CREATE]);

        $res = $this->postJson("/api/v1/projects?org={$this->org->slug}", [
            'name' => 'Standalone', 'delivery_days_own' => 40, 'delivery_days_oem' => 60,
        ])->assertCreated();

        $this->assertSame(60, $res->json('data.delivery.oem_share'));
        $this->assertNull($res->json('data.delivery.oem_share_drift'));
    }

    public function test_drift_reports_zero_when_delivery_matched_the_plan(): void
    {
        // Zero is a real answer here, distinct from null.
        $this->actor([
            Permission::PIPELINE_VIEW, Permission::PIPELINE_UPDATE,
            Permission::PROJECTS_VIEW, Permission::PROJECTS_CREATE,
        ]);

        $this->postJson($this->url('/estimates'), [
            'name' => 'Phase 1', 'delivery_days_own' => 50, 'delivery_days_oem' => 50,
        ])->assertOk();

        $res = $this->postJson("/api/v1/projects?org={$this->org->slug}", [
            'name' => 'On plan', 'opportunity_id' => $this->deal->id,
            'delivery_days_own' => 25, 'delivery_days_oem' => 25,
        ])->assertCreated();

        $this->assertSame(0, $res->json('data.delivery.oem_share_drift'));
    }

    public function test_the_oem_cost_on_a_project_is_gated_like_every_other_figure(): void
    {
        $this->actor([Permission::PROJECTS_VIEW, Permission::PROJECTS_CREATE, Permission::PIPELINE_VIEW]);

        $project = Project::create([
            'organization_id' => $this->org->id, 'name' => 'Gated',
            'opportunity_id' => $this->deal->id,
            'delivery_days_own' => 40, 'delivery_days_oem' => 10, 'oem_ps_cost' => 12000,
        ]);

        $res = $this->getJson("/api/v1/projects/{$project->id}?org={$this->org->slug}")->assertOk();

        // Days visible, cost masked.
        $this->assertEquals(40, $res->json('data.delivery.days_own'));
        $this->assertNull($res->json('data.delivery.oem_ps_cost'));
    }
}
