<?php

namespace Tests\Feature\Authorization;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The role editor and the deny administration — increment 4 of the epic.
 *
 * The contract under test: built-ins are immutable templates, every
 * permission string is validated against the catalogue at write time, and a
 * definition change or a deny reaches enforcement with no further wiring.
 */
class RoleEditorTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
    }

    private function actor(array $permissions): User
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

    private function definition(array $overrides = []): array
    {
        return array_merge([
            'display_name'         => 'Records Clerk',
            'description'          => 'Reads the registers.',
            'scope'                => 'organization',
            'permissions'          => [Permission::EMPLOYEES_VIEW],
            'excluded_permissions' => [],
        ], $overrides);
    }

    // ── Creating and validating ───────────────────────────────────────────

    public function test_creating_a_role_needs_roles_manage(): void
    {
        $this->actor([Permission::ROLES_VIEW]);

        $this->postJson($this->url('/authorization/roles'), $this->definition())
            ->assertForbidden();
    }

    public function test_a_typo_is_refused_at_write_time(): void
    {
        $this->actor([Permission::ROLES_MANAGE]);

        // The whole point of write-time validation: this typo would
        // otherwise sit in the role silently granting nothing forever.
        $this->postJson($this->url('/authorization/roles'), $this->definition([
            'permissions' => ['payrol.view'],
        ]))->assertStatus(422);

        $this->postJson($this->url('/authorization/roles'), $this->definition([
            'permissions' => ['payrol.*'],
        ]))->assertStatus(422);

        // And the bare * is not writable — it belongs to the seeded admin.
        $this->postJson($this->url('/authorization/roles'), $this->definition([
            'permissions' => ['*'],
        ]))->assertStatus(422);
    }

    public function test_an_exclusion_must_be_covered_by_a_grant(): void
    {
        $this->actor([Permission::ROLES_MANAGE]);

        // Excluding something nothing grants is a note that does nothing.
        $this->postJson($this->url('/authorization/roles'), $this->definition([
            'permissions'          => [Permission::EMPLOYEES_VIEW],
            'excluded_permissions' => [Permission::PAYROLL_APPROVE],
        ]))->assertStatus(422);

        // Under a covering wildcard it is the feature working as intended.
        $this->postJson($this->url('/authorization/roles'), $this->definition([
            'permissions'          => ['payroll.*'],
            'excluded_permissions' => [Permission::PAYROLL_APPROVE],
        ]))->assertStatus(201);
    }

    public function test_a_wildcard_role_created_in_the_editor_enforces(): void
    {
        $admin = $this->actor([Permission::ROLES_MANAGE]);

        $roleId = $this->postJson($this->url('/authorization/roles'), $this->definition([
            'permissions'          => ['leave.*'],
            'excluded_permissions' => [Permission::LEAVE_APPROVE],
        ]))->assertStatus(201)->json('data.id');

        $holder = User::factory()->create();
        $holder->roles()->attach($roleId, ['organization_id' => $this->org->id]);
        $holder->forgetCachedRoles();

        $this->assertTrue($holder->hasPermission(Permission::LEAVE_VIEW));
        $this->assertFalse($holder->hasPermission(Permission::LEAVE_APPROVE));
    }

    // ── Built-ins are templates ───────────────────────────────────────────

    public function test_a_built_in_role_cannot_be_edited_or_deleted(): void
    {
        $this->actor([Permission::ROLES_MANAGE]);

        $system = Role::create([
            'name' => 'org_hr_t', 'display_name' => 'HR', 'scope' => 'organization',
            'is_system' => true, 'permissions' => ['employees.*'],
        ]);

        $this->putJson($this->url("/authorization/roles/{$system->id}"), $this->definition())
            ->assertStatus(422);
        $this->deleteJson($this->url("/authorization/roles/{$system->id}"))
            ->assertStatus(422);
    }

    public function test_cloning_copies_the_definition_into_an_editable_role(): void
    {
        $this->actor([Permission::ROLES_MANAGE]);

        $system = Role::create([
            'name' => 'org_hr_t', 'display_name' => 'HR', 'scope' => 'organization',
            'is_system' => true,
            'permissions' => ['employees.*'], 'excluded_permissions' => [Permission::EMPLOYEES_DELETE],
        ]);

        $copyId = $this->postJson($this->url("/authorization/roles/{$system->id}/clone"), [
            'display_name' => 'HR minus sensitive',
        ])->assertStatus(201)->json('data.id');

        $copy = Role::findOrFail($copyId);
        $this->assertFalse($copy->is_system);
        $this->assertSame(['employees.*'], $copy->permissions);
        $this->assertSame([Permission::EMPLOYEES_DELETE], $copy->excluded_permissions);
        $this->assertNotSame($system->name, $copy->name);

        // The payoff move: untick one permission on the copy.
        $this->putJson($this->url("/authorization/roles/{$copyId}"), $this->definition([
            'display_name'         => 'HR minus sensitive',
            'permissions'          => ['employees.*'],
            'excluded_permissions' => [Permission::EMPLOYEES_VIEW_SENSITIVE],
        ]))->assertOk();

        $holder = User::factory()->create();
        $holder->roles()->attach($copyId, ['organization_id' => $this->org->id]);
        $holder->forgetCachedRoles();

        $this->assertTrue($holder->hasPermission(Permission::EMPLOYEES_VIEW));
        $this->assertFalse($holder->hasPermission(Permission::EMPLOYEES_VIEW_SENSITIVE));
    }

    public function test_a_held_role_cannot_be_deleted(): void
    {
        $admin = $this->actor([Permission::ROLES_MANAGE]);

        $role = Role::create([
            'name' => 'held_role', 'display_name' => 'Held', 'scope' => 'organization',
            'permissions' => [Permission::EMPLOYEES_VIEW],
        ]);
        $holder = User::factory()->create();
        $holder->roles()->attach($role->id, ['organization_id' => $this->org->id]);

        $this->deleteJson($this->url("/authorization/roles/{$role->id}"))
            ->assertStatus(422);

        // Revoked holders do not block deletion — the trail lives in audit.
        \Illuminate\Support\Facades\DB::table('user_roles')
            ->where('role_id', $role->id)->update(['revoked_at' => now()]);

        $this->deleteJson($this->url("/authorization/roles/{$role->id}"))
            ->assertOk();
    }

    // ── Denies ────────────────────────────────────────────────────────────

    public function test_a_deny_needs_a_reason_and_beats_the_grant(): void
    {
        $admin = $this->actor([Permission::ROLES_MANAGE]);

        $subject = $this->makeHolder([Permission::PAYROLL_VIEW]);
        $this->assertTrue($subject->hasPermission(Permission::PAYROLL_VIEW));

        // No reason, no deny.
        $this->postJson($this->url('/authorization/denies'), [
            'user_id' => $subject->id, 'permission' => Permission::PAYROLL_VIEW,
        ])->assertStatus(422);

        $this->postJson($this->url('/authorization/denies'), [
            'user_id' => $subject->id, 'permission' => Permission::PAYROLL_VIEW,
            'reason'  => 'Under investigation',
        ])->assertStatus(201);

        $subject->forgetCachedRoles();
        $this->assertFalse($subject->fresh()->hasPermission(Permission::PAYROLL_VIEW));
    }

    public function test_a_wildcard_deny_demands_explicit_confirmation(): void
    {
        $this->actor([Permission::ROLES_MANAGE]);
        $subject = $this->makeHolder([Permission::PAYROLL_VIEW]);

        $payload = [
            'user_id' => $subject->id, 'permission' => 'payroll.*',
            'reason'  => 'Leaving payroll duty',
        ];

        $this->postJson($this->url('/authorization/denies'), $payload)->assertStatus(422);
        $this->postJson($this->url('/authorization/denies'), [...$payload, 'confirm_wildcard' => true])
            ->assertStatus(201);
    }

    public function test_you_cannot_deny_yourself(): void
    {
        $admin = $this->actor([Permission::ROLES_MANAGE]);

        // The classic self-lockout: denying yourself the controls that undo it.
        $this->postJson($this->url('/authorization/denies'), [
            'user_id' => $admin->id, 'permission' => Permission::ROLES_MANAGE,
            'reason'  => 'oops', 'confirm_wildcard' => true,
        ])->assertStatus(422);
    }

    public function test_lifting_a_deny_restores_the_grant(): void
    {
        $this->actor([Permission::ROLES_MANAGE]);
        $subject = $this->makeHolder([Permission::PAYROLL_VIEW]);

        $denyId = $this->postJson($this->url('/authorization/denies'), [
            'user_id' => $subject->id, 'permission' => Permission::PAYROLL_VIEW,
            'reason'  => 'Temporary',
        ])->json('data.id');

        $this->deleteJson($this->url("/authorization/denies/{$denyId}"))->assertOk();

        $subject->forgetCachedRoles();
        $this->assertTrue($subject->fresh()->hasPermission(Permission::PAYROLL_VIEW));
    }

    private function makeHolder(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'h' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Holder', 'scope' => 'organization', 'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();

        return $user;
    }
}
