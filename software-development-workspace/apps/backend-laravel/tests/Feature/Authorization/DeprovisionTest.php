<?php

namespace Tests\Feature\Authorization;

use App\Models\Integration\IntegrationConnection;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\Account\DefaultReferenceData;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Taking somebody's access away.
 *
 * The thing worth proving is that a disable actually disables. Before this,
 * nothing on the way in read `account_status` — so the column changed and the
 * person carried on signing in, by password and by Microsoft alike.
 */
class DeprovisionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
        DefaultReferenceData::seedFor($this->org->id);
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

    private function tenant(): void
    {
        $conn = IntegrationConnection::create([
            'organization_id' => $this->org->id, 'name' => 'MS', 'provider' => 'microsoft',
            'tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'is_active' => true,
        ]);
        $this->org->update(['identity_connection_id' => $conn->id]);
    }

    private function url(User $u, string $path): string
    {
        return "/api/v1/authorization/users/{$u->id}{$path}?org={$this->org->slug}";
    }

    private function fakeGraphOk(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'tok'], 200),
            '*/revokeSignInSessions'      => Http::response([], 204),
            'graph.microsoft.com/*'       => Http::response([], 204),
        ]);
    }

    // ── The gate that makes any of this real ──────────────────────────────

    public function test_a_disabled_account_cannot_sign_in_with_its_password(): void
    {
        // Until the gate existed, `account_status` was read by nothing on the
        // way in: disabling changed a column and the person carried on.
        $person = User::factory()->create([
            'password'       => Hash::make('correct-horse-battery'),
            'account_status' => User::STATUS_DISABLED,
        ]);

        $this->postJson('/api/login', ['email' => $person->email, 'password' => 'correct-horse-battery'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_a_suspended_account_is_told_something_different(): void
    {
        $person = User::factory()->create([
            'password'       => Hash::make('correct-horse-battery'),
            'account_status' => User::STATUS_SUSPENDED,
        ]);

        $res = $this->postJson('/api/login', ['email' => $person->email, 'password' => 'correct-horse-battery'])
            ->assertStatus(422);

        $this->assertStringContainsString('suspended', $res->json('errors.email.0'));
    }

    public function test_a_pending_account_still_signs_in(): void
    {
        // The gate is on decisions somebody made — suspended, disabled — not on
        // the absence of one. 88 of 89 logins here are `pending`, set by the
        // importer and never cleared; gating on `active` would lock out the
        // whole organisation.
        $person = User::factory()->create([
            'password'       => Hash::make('correct-horse-battery'),
            'account_status' => User::STATUS_PENDING,
        ]);

        $this->postJson('/api/login', ['email' => $person->email, 'password' => 'correct-horse-battery'])
            ->assertOk()->assertJsonStructure(['token']);
    }

    // ── Deprovisioning ────────────────────────────────────────────────────

    public function test_deprovisioning_disables_here_and_in_office_365(): void
    {
        $this->actor([Permission::ROLES_VIEW, Permission::USERS_DEPROVISION]);
        $this->tenant();
        $this->fakeGraphOk();

        $person = User::factory()->create(['azure_id' => 'oid-1']);
        $person->createToken('live-session');

        $res = $this->postJson($this->url($person, '/deprovision'), ['confirm_email' => $person->email])
            ->assertOk();

        $this->assertSame(User::STATUS_DISABLED, $person->fresh()->account_status);
        $this->assertSame('disabled', $res->json('result.directory'));

        // The sessions they were holding, gone now rather than on expiry.
        $this->assertSame(0, $person->fresh()->tokens()->count());

        Http::assertSent(fn ($r) => str_contains($r->url(), 'revokeSignInSessions'));
    }

    public function test_the_address_has_to_be_typed_back(): void
    {
        // The list is alphabetical and the rows look alike. Typing the address
        // means the person has read which row they are on.
        $this->actor([Permission::ROLES_VIEW, Permission::USERS_DEPROVISION]);

        $person = User::factory()->create();

        $this->postJson($this->url($person, '/deprovision'), ['confirm_email' => 'someone.else@example.com'])
            ->assertStatus(422)->assertJsonValidationErrors('confirm_email');

        $this->assertNotSame(User::STATUS_DISABLED, $person->fresh()->account_status);
    }

    public function test_you_cannot_deprovision_yourself(): void
    {
        $me = $this->actor([Permission::ROLES_VIEW, Permission::USERS_DEPROVISION]);

        $res = $this->postJson($this->url($me, '/deprovision'), ['confirm_email' => $me->email])
            ->assertStatus(422);

        $this->assertStringContainsString('your own account', $res->json('errors.confirm_email.0'));
        $this->assertNotSame(User::STATUS_DISABLED, $me->fresh()->account_status);
    }

    public function test_somebody_with_no_office_365_account_is_still_deprovisioned(): void
    {
        $this->actor([Permission::ROLES_VIEW, Permission::USERS_DEPROVISION]);
        $this->tenant();

        $person = User::factory()->create(['azure_id' => null]);

        $res = $this->postJson($this->url($person, '/deprovision'), ['confirm_email' => $person->email])
            ->assertOk();

        $this->assertSame(User::STATUS_DISABLED, $person->fresh()->account_status);
        $this->assertSame('not_in_directory', $res->json('result.directory'));
    }

    public function test_a_refused_directory_call_still_locks_them_out_here_and_says_so(): void
    {
        // A partial result reported beats an all-or-nothing that silently
        // leaves somebody signed in.
        $this->actor([Permission::ROLES_VIEW, Permission::USERS_DEPROVISION]);
        $this->tenant();

        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'tok'], 200),
            'graph.microsoft.com/*'       => Http::response(['error' => ['message' => 'Insufficient privileges']], 403),
        ]);

        $person = User::factory()->create(['azure_id' => 'oid-2']);

        $res = $this->postJson($this->url($person, '/deprovision'), ['confirm_email' => $person->email])
            ->assertOk();

        $this->assertSame(User::STATUS_DISABLED, $person->fresh()->account_status);
        $this->assertSame('failed', $res->json('result.directory'));
        $this->assertStringContainsString('User.ReadWrite.All', $res->json('message'));
    }

    public function test_deprovisioning_needs_its_own_permission(): void
    {
        // Not inherited from being able to grant a role: this disables a
        // Microsoft account and closes live sessions.
        $this->actor([Permission::ROLES_VIEW, Permission::ROLES_ASSIGN]);

        $person = User::factory()->create();

        $this->postJson($this->url($person, '/deprovision'), ['confirm_email' => $person->email])
            ->assertForbidden();
    }

    // ── Putting somebody back ─────────────────────────────────────────────

    public function test_a_leaver_by_mistake_can_be_restored(): void
    {
        $this->actor([Permission::ROLES_VIEW, Permission::USERS_DEPROVISION]);
        $this->tenant();
        $this->fakeGraphOk();

        $person = User::factory()->create([
            'azure_id' => 'oid-3', 'account_status' => User::STATUS_DISABLED,
            'password' => Hash::make('correct-horse-battery'),
        ]);

        $this->postJson($this->url($person, '/reprovision'))->assertOk();

        // `pending`, not `active`: letting somebody back in is not the same as
        // asserting the account is in good order.
        $this->assertSame(User::STATUS_PENDING, $person->fresh()->account_status);
    }

    public function test_a_restored_account_can_sign_in_again(): void
    {
        /*
         * Its own test rather than a second assertion on the one above:
         * `Sanctum::actingAs` swaps the default guard for a RequestGuard, which
         * has no `attempt()`, so a login cannot be exercised in a test that has
         * already acted as somebody.
         */
        $this->tenant();
        $this->fakeGraphOk();

        $person = User::factory()->create([
            'azure_id' => 'oid-4', 'account_status' => User::STATUS_DISABLED,
            'password' => Hash::make('correct-horse-battery'),
        ]);

        $this->postJson('/api/login', ['email' => $person->email, 'password' => 'correct-horse-battery'])
            ->assertStatus(422);

        app(\App\Services\Identity\Deprovisioner::class)::forOrganization($this->org->id)->restore($person);

        $this->postJson('/api/login', ['email' => $person->email, 'password' => 'correct-horse-battery'])
            ->assertOk()->assertJsonStructure(['token']);
    }
}
