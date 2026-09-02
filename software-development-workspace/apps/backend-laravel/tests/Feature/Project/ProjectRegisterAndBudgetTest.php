<?php

namespace Tests\Feature\Project;

use App\Models\Account\Account;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Project\Project;
use App\Models\Role;
use App\Models\Training\VendorPartner;
use App\Models\User;
use App\Support\Account\DefaultReferenceData;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 2 of the project module: the register (dependencies, risks) and the
 * money (budget lines vs expenses, per currency) and the vendors on a project.
 *
 * What is worth proving: that budget and spend are compared honestly per
 * currency (never summed across currencies), that an over-run is flagged, that
 * a dependency and risk are org-scoped and child-of-project guarded, and that a
 * vendor can either link a known partner or carry a free name.
 */
class ProjectRegisterAndBudgetTest extends TestCase
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

        $this->project = Project::create([
            'organization_id' => $this->org->id, 'name' => 'SD-WAN Rollout',
        ]);
    }

    private function actor(array $permissions): void
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Test', 'scope' => 'organization', 'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);
    }

    private function url(string $path): string
    {
        return "/api/v1/projects/{$this->project->id}{$path}?org={$this->org->slug}";
    }

    // ── Dependencies ────────────────────────────────────────────────────────

    public function test_a_dependency_is_added_and_status_moved(): void
    {
        $this->actor([Permission::PROJECTS_VIEW, Permission::PROJECTS_UPDATE]);

        $res = $this->postJson($this->url('/dependencies'), [
            'title' => 'Circuit delivery from the ISP', 'kind' => 'vendor',
        ])->assertCreated();

        $id = $res->json('data.id');
        $this->assertSame('pending', $res->json('data.status'));

        $this->putJson($this->url("/dependencies/{$id}"), ['status' => 'resolved'])
            ->assertOk()->assertJsonPath('data.status', 'resolved');
    }

    public function test_dependencies_require_the_update_permission(): void
    {
        $this->actor([Permission::PROJECTS_VIEW]);

        $this->postJson($this->url('/dependencies'), ['title' => 'x'])->assertForbidden();
    }

    // ── Risks ───────────────────────────────────────────────────────────────

    public function test_a_risk_carries_a_heat_score_and_ranks_hottest_first(): void
    {
        $this->actor([Permission::PROJECTS_VIEW, Permission::PROJECTS_UPDATE]);

        $this->postJson($this->url('/risks'), [
            'title' => 'Low one', 'likelihood' => 'low', 'impact' => 'low',
        ])->assertCreated()->assertJsonPath('data.score', 1);

        $this->postJson($this->url('/risks'), [
            'title' => 'Hot one', 'likelihood' => 'high', 'impact' => 'high',
        ])->assertCreated()->assertJsonPath('data.score', 9);

        $list = $this->getJson($this->url('/risks'))->assertOk();
        // Hottest (score 9) sorts above the score-1 risk.
        $this->assertSame('Hot one', $list->json('data.0.title'));
    }

    // ── Finance: budget vs expenditure ──────────────────────────────────────

    public function test_budget_and_spend_are_compared_per_currency_and_flag_overrun(): void
    {
        $this->actor([Permission::PROJECTS_VIEW, Permission::PROJECTS_UPDATE]);

        $this->postJson($this->url('/finance/budget-lines'), [
            'label' => 'Hardware', 'currency' => 'KES', 'amount' => 1000000,
        ])->assertCreated();
        $this->postJson($this->url('/finance/budget-lines'), [
            'label' => 'Licences', 'currency' => 'USD', 'amount' => 5000,
        ])->assertCreated();

        // Overspend the KES budget.
        $snap = $this->postJson($this->url('/finance/expenses'), [
            'label' => 'Routers', 'currency' => 'KES', 'amount' => 1200000,
        ])->assertCreated();

        $kes = collect($snap->json('summary'))->firstWhere('currency', 'KES');
        $this->assertEquals(1000000, $kes['budgeted']);
        $this->assertEquals(1200000, $kes['spent']);
        $this->assertEquals(-200000, $kes['remaining']);
        $this->assertTrue($kes['over_budget']);

        // USD line stands on its own, never merged with KES.
        $usd = collect($snap->json('summary'))->firstWhere('currency', 'USD');
        $this->assertEquals(5000, $usd['budgeted']);
        $this->assertEquals(0, $usd['spent']);
        $this->assertFalse($usd['over_budget']);
    }

    // ── Vendors ─────────────────────────────────────────────────────────────

    public function test_a_vendor_links_a_partner_or_carries_a_free_name(): void
    {
        $this->actor([Permission::PROJECTS_VIEW, Permission::PROJECTS_UPDATE]);

        $partner = VendorPartner::create([
            'organization_id' => $this->org->id, 'name' => 'Cisco Systems',
        ]);

        $this->postJson($this->url('/vendors'), ['vendor_partner_id' => $partner->id])
            ->assertCreated()->assertJsonPath('data.name', 'Cisco Systems')
            ->assertJsonPath('data.partner.id', $partner->id);

        $this->postJson($this->url('/vendors'), ['name' => 'Local cabling contractor'])
            ->assertCreated()->assertJsonPath('data.name', 'Local cabling contractor')
            ->assertJsonPath('data.partner', null);

        $this->postJson($this->url('/vendors'), [])->assertStatus(422);
    }
}
