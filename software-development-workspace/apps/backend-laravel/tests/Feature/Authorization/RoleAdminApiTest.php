<?php

namespace Tests\Feature\Authorization;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permission;
use App\Support\Authorization\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The authorization administration surface.
 *
 * The escalation guard is what most of this covers. An administration screen
 * that can grant roles is, without it, a screen that can grant *itself* more
 * than it holds — the person operating it need only assign themselves a role
 * that is more powerful than their own.
 */
class RoleAdminApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $alpha;
    private Organization $beta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        $this->beta  = Organization::create(['name' => 'Beta',  'slug' => 'beta']);
    }

    private function role(string $name, array $permissions, ?array $assignable = null): Role
    {
        return Role::create([
            'name'              => $name,
            'display_name'      => ucfirst($name),
            'scope'             => 'organization',
            'permissions'       => $permissions,
            'assignable_scopes' => $assignable,
        ]);
    }

    /**
     * $roleName is incidental. Tenancy reach is decided by the assignment's
     * scope, so a group-scoped role works under any name — see
     * groupAdmin() below, which deliberately uses a generated one.
     */
    private function user(
        array $permissions,
        ?Organization $org = null,
        string $scope = Scope::ORGANIZATION,
        ?string $roleName = null,
    ): User {
        $user = User::factory()->create();
        $role = $this->role(
            $roleName ?? ('r' . fake()->unique()->numberBetween(1, 999999)),
            $permissions,
        );
        $user->roles()->attach($role->id, [
            'organization_id' => $org?->id,
            'scope'           => $scope,
        ]);
        $user->forgetCachedRoles();

        return $user;
    }

    /**
     * A group-wide administrator under a *generated* name.
     *
     * Deliberately not called group_admin: the middleware used to gate on that
     * name, so this asserts the tenancy check now follows scope instead.
     */
    private function groupAdmin(): User
    {
        return $this->user(['*'], null, Scope::GROUP);
    }

    private function headers(): array
    {
        return ['X-Organization' => $this->alpha->slug, 'Accept' => 'application/json'];
    }

    // ── Access to the screen itself ───────────────────────────────────────────

    public function test_the_screen_requires_a_permission(): void
    {
        Sanctum::actingAs($this->user([], $this->alpha));

        $this->getJson('/api/v1/authorization/roles', $this->headers())->assertForbidden();
    }

    public function test_viewing_does_not_confer_granting(): void
    {
        // Reviewing who holds what is an audit task; widening access is not.
        Sanctum::actingAs($this->user([Permission::ROLES_VIEW], $this->alpha));

        $this->getJson('/api/v1/authorization/roles', $this->headers())->assertOk();

        $this->postJson('/api/v1/authorization/assignments', [
            'user_id' => User::factory()->create()->id,
            'role_id' => $this->role('target', [])->id,
            'organization_id' => $this->alpha->id,
            'scope'   => Scope::ORGANIZATION,
        ], $this->headers())->assertForbidden();
    }

    // ── Permissions are returned grouped and reviewable ───────────────────────

    public function test_a_role_reports_its_permissions_grouped_by_resource(): void
    {
        Sanctum::actingAs($this->groupAdmin());
        $role = $this->role('leave_admin', ['leave.*'], null);
        $role->update(['excluded_permissions' => [Permission::LEAVE_APPROVE]]);

        $body = $this->getJson("/api/v1/authorization/roles/{$role->id}", $this->headers())
            ->assertOk()
            ->json('data');

        $leave = collect($body['permission_groups'])->firstWhere('resource', 'leave');

        $states = collect($leave['permissions'])->pluck('state', 'permission');

        $this->assertSame('granted',  $states[Permission::LEAVE_VIEW]);
        // Exclusion beats the wildcard, matching how the check resolves.
        $this->assertSame('excluded', $states[Permission::LEAVE_APPROVE]);
    }

    // ── The tenant dimension ──────────────────────────────────────────────────

    public function test_one_identity_holds_different_roles_in_different_tenants(): void
    {
        $admin   = $this->groupAdmin();
        $subject = User::factory()->create();

        $hr       = $this->role('hr_alpha', ['employees.view']);
        $employee = $this->role('emp_beta', ['employees.view_own']);

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/authorization/assignments', [
            'user_id' => $subject->id, 'role_id' => $hr->id,
            'organization_id' => $this->alpha->id, 'scope' => Scope::ORGANIZATION,
        ], $this->headers())->assertCreated();

        $this->postJson('/api/v1/authorization/assignments', [
            'user_id' => $subject->id, 'role_id' => $employee->id,
            'organization_id' => $this->beta->id, 'scope' => Scope::SELF,
        ], $this->headers())->assertCreated();

        $assignments = $this->getJson("/api/v1/authorization/users/{$subject->id}/assignments", $this->headers())
            ->assertOk()
            ->json('data.assignments');

        $this->assertCount(2, $assignments);

        // The point of the whole design: broad in one tenant, narrow in another.
        $subject->forgetCachedRoles();
        $this->assertTrue($subject->hasPermission(Permission::EMPLOYEES_VIEW, $this->alpha->id));
        $this->assertFalse($subject->hasPermission(Permission::EMPLOYEES_VIEW, $this->beta->id));
    }

    // ── Escalation guard ──────────────────────────────────────────────────────

    public function test_a_granter_cannot_hand_out_what_they_do_not_hold(): void
    {
        // Holds roles.assign and leave, but nothing on payroll.
        $granter = $this->user([Permission::ROLES_ASSIGN, 'leave.*'], $this->alpha);
        $payroll = $this->role('payroll_admin', ['payroll.*']);

        Sanctum::actingAs($granter);

        $this->postJson('/api/v1/authorization/assignments', [
            'user_id' => User::factory()->create()->id,
            'role_id' => $payroll->id,
            'organization_id' => $this->alpha->id,
            'scope'   => Scope::ORGANIZATION,
        ], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'payroll'));
    }

    public function test_a_granter_cannot_exceed_their_own_scope(): void
    {
        // Holds leave at team scope only.
        $granter = $this->user([Permission::ROLES_ASSIGN, 'leave.*'], $this->alpha, Scope::TEAM);
        $role    = $this->role('leave_org', ['leave.view']);

        Sanctum::actingAs($granter);

        $this->postJson('/api/v1/authorization/assignments', [
            'user_id' => User::factory()->create()->id,
            'role_id' => $role->id,
            'organization_id' => $this->alpha->id,
            'scope'   => Scope::ORGANIZATION,
        ], $this->headers())->assertStatus(422);
    }

    public function test_a_wildcard_grant_requires_holding_every_member(): void
    {
        // Holds one leave permission, not the family.
        $granter = $this->user([Permission::ROLES_ASSIGN, Permission::LEAVE_VIEW], $this->alpha);
        $role    = $this->role('all_leave', ['leave.*']);

        Sanctum::actingAs($granter);

        $this->postJson('/api/v1/authorization/assignments', [
            'user_id' => User::factory()->create()->id,
            'role_id' => $role->id,
            'organization_id' => $this->alpha->id,
            'scope'   => Scope::ORGANIZATION,
        ], $this->headers())->assertStatus(422);
    }

    public function test_a_role_cannot_be_granted_outside_its_assignable_scopes(): void
    {
        $granter = $this->groupAdmin();
        $role    = $this->role('dept_only', ['employees.view'], [Scope::SELF, Scope::TEAM, Scope::DEPARTMENT]);

        Sanctum::actingAs($granter);

        $this->postJson('/api/v1/authorization/assignments', [
            'user_id' => User::factory()->create()->id,
            'role_id' => $role->id,
            'organization_id' => $this->alpha->id,
            'scope'   => Scope::ORGANIZATION,
        ], $this->headers())->assertStatus(422);
    }

    // ── Revocation ────────────────────────────────────────────────────────────

    public function test_revoking_marks_the_row_rather_than_deleting_it(): void
    {
        $admin   = $this->groupAdmin();
        $subject = User::factory()->create();
        $role    = $this->role('temp', ['employees.view']);

        Sanctum::actingAs($admin);

        $id = $this->postJson('/api/v1/authorization/assignments', [
            'user_id' => $subject->id, 'role_id' => $role->id,
            'organization_id' => $this->alpha->id, 'scope' => Scope::ORGANIZATION,
        ], $this->headers())->json('data.id');

        $this->deleteJson("/api/v1/authorization/assignments/{$id}", [
            'reason' => 'Left the department',
        ], $this->headers())->assertOk();

        // Row retained so "who had access last quarter" stays answerable.
        $row = DB::table('user_roles')->find($id);
        $this->assertNotNull($row);
        $this->assertNotNull($row->revoked_at);

        $subject->forgetCachedRoles();
        $this->assertFalse($subject->hasPermission(Permission::EMPLOYEES_VIEW, $this->alpha->id));
    }

    public function test_granting_twice_does_not_duplicate_the_assignment(): void
    {
        $admin   = $this->groupAdmin();
        $subject = User::factory()->create();
        $role    = $this->role('once', ['employees.view']);

        Sanctum::actingAs($admin);

        $payload = [
            'user_id' => $subject->id, 'role_id' => $role->id,
            'organization_id' => $this->alpha->id, 'scope' => Scope::ORGANIZATION,
        ];

        $first  = $this->postJson('/api/v1/authorization/assignments', $payload, $this->headers())->json('data.id');
        $second = $this->postJson('/api/v1/authorization/assignments', $payload, $this->headers())->json('data.id');

        // A duplicate row would make revocation ambiguous.
        $this->assertSame($first, $second);
    }
}
