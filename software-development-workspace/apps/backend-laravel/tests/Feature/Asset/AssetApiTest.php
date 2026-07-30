<?php

namespace Tests\Feature\Asset;

use App\Models\Asset\Asset;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Assets HTTP surface.
 *
 * AssetPortTest covers the policy in isolation. This covers the thing that
 * test cannot: that the routes actually reach the policy. A correct policy
 * behind a route that never calls it is the shape of every authorization gap
 * found in this codebase.
 */
class AssetApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
    }

    private function actingAs_(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name'         => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Test',
            'scope'        => 'organization',
            'permissions'  => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();

        Employee::create([
            'employee_number'         => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id,
            'first_name'              => 'Test', 'last_name' => 'User',
            'employment_type'         => 'permanent', 'date_hired' => '2024-01-01',
            'user_id'                 => $user->id,
        ]);

        Sanctum::actingAs($user);

        return $user;
    }

    private function headers(): array
    {
        return ['X-Organization' => $this->org->slug, 'Accept' => 'application/json'];
    }

    private function employee(): Employee
    {
        return Employee::create([
            'employee_number'         => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id,
            'first_name'              => fake()->firstName(), 'last_name' => fake()->lastName(),
            'employment_type'         => 'permanent', 'date_hired' => '2024-01-01',
        ]);
    }

    private function asset(array $attrs = []): Asset
    {
        return Asset::create(array_merge([
            'organization_id' => $this->org->id,
            'name'            => 'ThinkPad',
            'asset_tag'       => 'AST-' . fake()->unique()->numberBetween(1000, 999999),
            'category'        => 'laptop',
        ], $attrs));
    }

    // ── The routes reach the policy ───────────────────────────────────────────

    public function test_listing_requires_a_permission(): void
    {
        $this->actingAs_([]);

        $this->getJson('/api/v1/assets', $this->headers())->assertForbidden();
    }

    public function test_creating_requires_a_permission(): void
    {
        $this->actingAs_(['assets.view']);

        $this->postJson('/api/v1/assets', [
            'name' => 'Dell XPS', 'asset_tag' => 'AST-9001', 'category' => 'laptop',
        ], $this->headers())->assertForbidden();
    }

    public function test_a_permitted_user_can_list_and_create(): void
    {
        $this->actingAs_(['assets.*']);
        $this->asset();

        $this->getJson('/api/v1/assets', $this->headers())
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name', 'asset_tag', 'is_assigned']]]);

        $this->postJson('/api/v1/assets', [
            'name' => 'Dell XPS', 'asset_tag' => 'AST-9001', 'category' => 'laptop',
        ], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.asset_tag', 'AST-9001')
            ->assertJsonPath('data.status', Asset::STATUS_AVAILABLE);
    }

    // ── Custody is its own permission ─────────────────────────────────────────

    public function test_update_permission_does_not_allow_assigning(): void
    {
        $this->actingAs_(['assets.view', 'assets.update']);
        $asset = $this->asset();

        $this->postJson("/api/v1/assets/{$asset->id}/assign", [
            'employee_id' => $this->employee()->id,
        ], $this->headers())->assertForbidden();
    }

    public function test_assigning_moves_holder_and_status_together(): void
    {
        $this->actingAs_(['assets.*']);
        $asset    = $this->asset();
        $employee = $this->employee();

        $this->postJson("/api/v1/assets/{$asset->id}/assign", [
            'employee_id' => $employee->id,
        ], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.status', Asset::STATUS_ASSIGNED)
            ->assertJsonPath('data.is_assigned', true)
            ->assertJsonPath('data.holder.id', $employee->id);
    }

    public function test_releasing_clears_the_holder(): void
    {
        $this->actingAs_(['assets.*']);
        $asset = $this->asset(['assigned_to' => $this->employee()->id, 'status' => Asset::STATUS_ASSIGNED]);

        $this->postJson("/api/v1/assets/{$asset->id}/release", [
            'status' => Asset::STATUS_MAINTENANCE,
        ], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.status', Asset::STATUS_MAINTENANCE)
            ->assertJsonPath('data.is_assigned', false);
    }

    public function test_an_update_cannot_smuggle_a_reassignment(): void
    {
        $this->actingAs_(['assets.*']);
        $employee = $this->employee();
        $asset    = $this->asset();

        $this->putJson("/api/v1/assets/{$asset->id}", [
            'name'        => 'Renamed',
            'assigned_to' => $employee->id,
        ], $this->headers())->assertOk();

        $this->assertNull($asset->fresh()->assigned_to, 'update() must not change custody.');
    }

    // ── Tenancy ───────────────────────────────────────────────────────────────

    public function test_an_asset_in_another_tenant_is_not_found(): void
    {
        $other   = Organization::create(['name' => 'Beta', 'slug' => 'beta']);
        $foreign = Asset::create([
            'organization_id' => $other->id, 'name' => 'Foreign',
            'asset_tag' => 'AST-BETA', 'category' => 'laptop',
        ]);

        $this->actingAs_(['assets.*']);

        // The global scope hides it entirely, so this is a 404 rather than a
        // 403 — the tenant should not learn the record exists.
        $this->getJson("/api/v1/assets/{$foreign->id}", $this->headers())->assertNotFound();
    }

    public function test_assigning_to_an_employee_from_another_tenant_is_rejected(): void
    {
        $other  = Organization::create(['name' => 'Beta', 'slug' => 'beta']);
        $absent = Employee::create([
            'employee_number' => 'E-BETA', 'primary_organization_id' => $other->id,
            'first_name' => 'Beta', 'last_name' => 'Person',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
        ]);

        $this->actingAs_(['assets.*']);
        $asset = $this->asset();

        // exists:employees,id passes — the validation rule knows nothing about
        // tenancy, which is exactly why the controller checks it separately.
        $this->postJson("/api/v1/assets/{$asset->id}/assign", [
            'employee_id' => $absent->id,
        ], $this->headers())->assertStatus(422);
    }

    // ── Filters ───────────────────────────────────────────────────────────────

    public function test_the_register_can_be_filtered_by_what_needs_attention(): void
    {
        $this->actingAs_(['assets.*']);
        $this->asset(['warranty_expiry_date' => now()->subDay()]);
        $this->asset(['warranty_expiry_date' => now()->addYear()]);

        $this->getJson('/api/v1/assets?warranty_expired=1', $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_a_disabled_account_is_refused(): void
    {
        $user = $this->actingAs_(['assets.*']);

        // Re-authenticate with the refreshed record. Sanctum::actingAs pins the
        // instance it is given, so updating the model afterwards leaves the
        // request using a stale object — in production the user is resolved
        // from the token on every request, so the guard sees current state.
        $user->update(['account_status' => User::STATUS_DISABLED]);
        Sanctum::actingAs($user->fresh());

        $this->getJson('/api/v1/assets', $this->headers())->assertForbidden();
    }
}
