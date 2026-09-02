<?php

namespace Tests\Feature\Vendor;

use App\Models\Organization;
use App\Models\Role;
use App\Models\Training\VendorPartner;
use App\Models\User;
use App\Models\Vendor\StakeholderRole;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A stakeholder holds several roles at once, each for a period.
 *
 * The module's first tests. What they pin: a party can be a distributor AND
 * an OEM (the thing a single `vendor_type` column could never express), a
 * role ends rather than vanishing, filtering asks only about roles in force,
 * and an individual cannot hold a company's roles.
 */
class StakeholderRoleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
    }

    private function actor(array $permissions = [Permission::VENDORS_VIEW, Permission::VENDORS_MANAGE]): User
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Test', 'scope' => 'organization', 'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);

        return $user;
    }

    private function url(string $path): string
    {
        return "/api/v1{$path}?org={$this->org->slug}";
    }

    private function register(array $overrides = []): array
    {
        return $this->postJson($this->url('/vendors'), array_merge([
            'name'  => 'Acme Networks',
            'roles' => ['distributor', 'oem'],
        ], $overrides))->assertStatus(201)->json('data');
    }

    // ── Several roles at once ─────────────────────────────────────────────

    public function test_a_party_can_be_a_distributor_and_an_oem(): void
    {
        $this->actor();

        // The exact case the boolean `is_oem_vendor` was bolted on for.
        $vendor = $this->register();

        $this->assertEqualsCanonicalizing(['distributor', 'oem'], $vendor['active_roles']);

        // The acronym keeps its case — title-casing renders it "Oem", which
        // reads as a typo on every screen the label reaches.
        $this->assertSame('OEM', collect($vendor['roles'])->firstWhere('role', 'oem')['label']);

        // The deprecated columns stay honest for the consumers still on them.
        $row = VendorPartner::findOrFail($vendor['id']);
        $this->assertSame('distributor', $row->vendor_type);
        $this->assertTrue($row->is_oem_vendor);
    }

    public function test_adding_a_role_leaves_the_existing_ones_alone(): void
    {
        $this->actor();
        $vendor = $this->register(['roles' => ['supplier']]);

        $updated = $this->postJson($this->url("/vendors/{$vendor['id']}/roles"), [
            'role' => 'subcontractor',
        ])->assertOk()->json('data');

        $this->assertEqualsCanonicalizing(['supplier', 'subcontractor'], $updated['active_roles']);
    }

    public function test_re_adding_a_role_in_force_is_a_no_op(): void
    {
        $this->actor();
        $vendor = $this->register(['roles' => ['supplier']]);

        $this->postJson($this->url("/vendors/{$vendor['id']}/roles"), ['role' => 'supplier'])->assertOk();

        // One row, not two — a duplicate would make the end date ambiguous.
        $this->assertSame(1, StakeholderRole::where('vendor_partner_id', $vendor['id'])->count());
    }

    // ── Ending a role keeps the history ───────────────────────────────────

    public function test_ending_a_role_keeps_the_row_and_drops_it_from_active(): void
    {
        $this->actor();
        $vendor = $this->register();

        $roleId = collect($vendor['roles'])->firstWhere('role', 'oem')['id'];

        $after = $this->patchJson($this->url("/vendors/{$vendor['id']}/roles/{$roleId}"), [
            'valid_until' => now()->subDay()->toDateString(),
        ])->assertOk()->json('data');

        // Gone from what they ARE…
        $this->assertSame(['distributor'], $after['active_roles']);
        // …but still in what they WERE, which is the question an auditor asks.
        $this->assertCount(2, $after['roles']);
        $this->assertFalse(collect($after['roles'])->firstWhere('role', 'oem')['in_force']);

        // And the derived legacy column followed it down.
        $this->assertFalse(VendorPartner::findOrFail($vendor['id'])->is_oem_vendor);
    }

    public function test_a_role_that_starts_tomorrow_is_not_in_force_yet(): void
    {
        $this->actor();
        $vendor = $this->register(['roles' => ['supplier']]);

        $after = $this->postJson($this->url("/vendors/{$vendor['id']}/roles"), [
            'role'       => 'distributor',
            'valid_from' => now()->addWeek()->toDateString(),
        ])->assertOk()->json('data');

        $this->assertSame(['supplier'], $after['active_roles']);
    }

    // ── Filtering ─────────────────────────────────────────────────────────

    public function test_filtering_by_role_asks_only_about_roles_in_force(): void
    {
        $this->actor();

        $current = $this->register(['name' => 'Still A Distributor', 'roles' => ['distributor']]);
        $past    = $this->register(['name' => 'Was A Distributor', 'roles' => ['distributor']]);

        $this->patchJson(
            $this->url("/vendors/{$past['id']}/roles/{$past['roles'][0]['id']}"),
            ['valid_until' => now()->subMonth()->toDateString()],
        )->assertOk();

        $names = array_column(
            $this->getJson($this->url('/vendors') . '&role=distributor')->assertOk()->json('data'),
            'name',
        );

        $this->assertContains('Still A Distributor', $names);
        $this->assertNotContains('Was A Distributor', $names);
    }

    // ── Party class ───────────────────────────────────────────────────────

    public function test_an_individual_cannot_be_a_distributor(): void
    {
        $this->actor();

        $this->postJson($this->url('/vendors'), [
            'name' => 'Jane Consulting', 'party_class' => 'individual',
            'roles' => ['distributor'],
        ])->assertStatus(422);
    }

    public function test_a_company_cannot_hold_the_individual_contractor_role(): void
    {
        $this->actor();

        $this->postJson($this->url('/vendors'), [
            'name' => 'Big Builders Ltd', 'roles' => ['contractor_individual'],
        ])->assertStatus(422);
    }

    public function test_an_individual_consultant_registers_cleanly(): void
    {
        $this->actor();

        $vendor = $this->postJson($this->url('/vendors'), [
            'name' => 'Jane Wanjiru', 'party_class' => 'individual',
            'roles' => ['consultant', 'contractor_individual'],
        ])->assertStatus(201)->json('data');

        $this->assertSame('individual', $vendor['party_class']);
        $this->assertEqualsCanonicalizing(['consultant', 'contractor_individual'], $vendor['active_roles']);
    }

    // ── Authorization ─────────────────────────────────────────────────────

    public function test_classifying_a_party_needs_more_than_read_access(): void
    {
        $this->actor();
        $vendor = $this->register(['roles' => ['supplier']]);

        $this->actor([Permission::VENDORS_VIEW]);

        $this->postJson($this->url("/vendors/{$vendor['id']}/roles"), ['role' => 'oem'])
            ->assertForbidden();
    }

    public function test_a_role_from_another_vendor_is_not_reachable(): void
    {
        $this->actor();
        $mine     = $this->register(['name' => 'Mine', 'roles' => ['supplier']]);
        $theirs   = $this->register(['name' => 'Theirs', 'roles' => ['supplier']]);
        $theirRole = $theirs['roles'][0]['id'];

        $this->patchJson($this->url("/vendors/{$mine['id']}/roles/{$theirRole}"), [])
            ->assertNotFound();
    }
}
