<?php

namespace Tests\Feature\Security;

use App\Models\Employee;
use App\Models\Integration\IntegrationConnection;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Provisioning, both directions.
 *
 * Onboarded here and absent from Office 365 gets an account made from the
 * record we already hold; present there and absent here becomes a suggestion
 * a person accepts. The second is never automatic — creating employee records
 * off a directory sync is how a shared mailbox ends up on the org chart.
 */
class ProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);

        $connection = IntegrationConnection::create([
            'organization_id' => $this->org->id,
            'name'            => 'Tenant',
            'provider'        => 'microsoft',
            'tenant_id'       => 'tenant-uuid',
            'client_id'       => 'client-uuid',
            'client_secret'   => 'secret',
            'is_active'       => true,
        ]);

        $this->org->update(['identity_connection_id' => $connection->id]);
    }

    private function actor(array $permissions = [Permission::ROLES_VIEW, Permission::USERS_PROVISION]): User
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

    private function token(): array
    {
        return ['login.microsoftonline.com/*' => Http::response(['access_token' => 'graph-token'], 200)];
    }

    // ── HRMS → Office 365 ─────────────────────────────────────────────────

    public function test_an_onboarded_starter_gets_an_account_made(): void
    {
        $this->actor();

        $starter = User::factory()->create(['email' => 'jane@kns.co.ke', 'name' => 'Jane Wanjiru']);
        Employee::create([
            'employee_number' => 'EMP-00001', 'primary_organization_id' => $this->org->id,
            'first_name' => 'Jane', 'last_name' => 'Wanjiru', 'country' => 'KE',
            'employment_type' => 'permanent', 'date_hired' => '2026-01-01', 'user_id' => $starter->id,
        ]);

        Http::fake([
            ...$this->token(),
            // Nobody there by that name yet.
            'graph.microsoft.com/v1.0/users/jane%40kns.co.ke*' => Http::response([], 404),
            'graph.microsoft.com/v1.0/users?*'                 => Http::response(['value' => []], 200),
            'graph.microsoft.com/v1.0/users'                   => Http::response(['id' => 'new-oid'], 201),
        ]);

        $body = $this->postJson($this->url("/authorization/users/{$starter->id}/provision"))
            ->assertOk()->json();

        $this->assertSame('created', $body['result']['status']);
        $this->assertNotEmpty($body['result']['temporary_password']);

        // Linked and moved onto the sign-in path that now exists.
        $starter->refresh();
        $this->assertSame('new-oid', $starter->azure_id);
        $this->assertSame(User::AUTH_SSO, $starter->auth_method);

        // The password is handed over once and kept nowhere.
        $this->assertDatabaseMissing('users', ['id' => $starter->id, 'password' => $body['result']['temporary_password']]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'identity.provisioned']);
    }

    public function test_the_directory_details_come_from_the_employee_record(): void
    {
        $this->actor();

        $starter = User::factory()->create(['email' => 'jane@kns.co.ke', 'name' => 'Jane Wanjiru']);
        Employee::create([
            'employee_number' => 'EMP-00001', 'primary_organization_id' => $this->org->id,
            'first_name' => 'Jane', 'last_name' => 'Wanjiru', 'phone' => '+254700000001',
            'country' => 'UG',
            'employment_type' => 'permanent', 'date_hired' => '2026-01-01', 'user_id' => $starter->id,
        ]);

        Http::fake([
            ...$this->token(),
            'graph.microsoft.com/v1.0/users/*' => Http::response([], 404),
            'graph.microsoft.com/v1.0/users?*' => Http::response(['value' => []], 200),
            'graph.microsoft.com/v1.0/users'   => Http::response(['id' => 'new-oid'], 201),
        ]);

        $this->postJson($this->url("/authorization/users/{$starter->id}/provision"))->assertOk();

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/v1.0/users') || $request->method() !== 'POST') {
                return false;
            }

            return $request['givenName'] === 'Jane'
                && $request['surname'] === 'Wanjiru'
                && $request['mobilePhone'] === '+254700000001'
                // Licensing needs the ISO code, not the country's name.
                && $request['usageLocation'] === 'UG'
                && $request['passwordProfile']['forceChangePasswordNextSignIn'] === true;
        });
    }

    public function test_an_account_that_already_exists_is_linked_not_duplicated(): void
    {
        $this->actor();
        $starter = User::factory()->create(['email' => 'jane@kns.co.ke']);

        Http::fake([
            ...$this->token(),
            'graph.microsoft.com/v1.0/users/jane%40kns.co.ke*' => Http::response([
                'id' => 'existing-oid', 'userPrincipalName' => 'jane@kns.co.ke',
            ], 200),
        ]);

        $body = $this->postJson($this->url("/authorization/users/{$starter->id}/provision"))
            ->assertOk()->json();

        // Two accounts for one person is worse than none: mail splits between
        // them and whichever holds the licence is the one that works.
        $this->assertSame('already_exists', $body['result']['status']);
        $this->assertSame('existing-oid', $starter->fresh()->azure_id);

        Http::assertNotSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/v1.0/users'));
    }

    public function test_a_login_already_linked_is_left_alone(): void
    {
        $this->actor();
        $starter = User::factory()->create(['email' => 'jane@kns.co.ke', 'azure_id' => 'already-there']);

        Http::fake($this->token());

        $body = $this->postJson($this->url("/authorization/users/{$starter->id}/provision"))
            ->assertOk()->json();

        $this->assertSame('already_exists', $body['result']['status']);
        $this->assertNull($body['result']['temporary_password']);
    }

    public function test_a_missing_write_grant_is_named_rather_than_guessed_at(): void
    {
        $this->actor();
        $starter = User::factory()->create(['email' => 'jane@kns.co.ke']);

        Http::fake([
            ...$this->token(),
            'graph.microsoft.com/v1.0/users/*' => Http::response([], 404),
            'graph.microsoft.com/v1.0/users?*' => Http::response(['value' => []], 200),
            'graph.microsoft.com/v1.0/users'   => Http::response(['error' => ['message' => 'Insufficient privileges']], 403),
        ]);

        $this->postJson($this->url("/authorization/users/{$starter->id}/provision"))
            ->assertStatus(422)
            ->assertSee('User.ReadWrite.All', false);
    }

    // ── Office 365 → HRMS ─────────────────────────────────────────────────

    public function test_accounts_nothing_here_explains_are_offered_as_suggestions(): void
    {
        $this->actor();

        // Already here, by object id and by address respectively.
        User::factory()->create(['email' => 'known@kns.co.ke', 'azure_id' => 'known-oid']);
        User::factory()->create(['email' => 'byaddress@kns.co.ke']);

        Http::fake([
            ...$this->token(),
            'graph.microsoft.com/v1.0/users*' => Http::response(['value' => [
                ['id' => 'known-oid',   'displayName' => 'Known Person',  'userPrincipalName' => 'known@kns.co.ke',     'userType' => 'Member'],
                ['id' => 'other-oid',   'displayName' => 'By Address',    'userPrincipalName' => 'byaddress@kns.co.ke', 'userType' => 'Member'],
                ['id' => 'stranger',    'displayName' => 'Mary Atieno',   'userPrincipalName' => 'mary@kns.co.ke',      'userType' => 'Member',
                 'jobTitle' => 'Field Engineer', 'department' => 'Operations'],
                ['id' => 'guest-oid',   'displayName' => 'Someone Else',  'userPrincipalName' => 'x@partner.com',       'userType' => 'Guest'],
            ]], 200),
        ]);

        $data = $this->getJson($this->url('/authorization/directory/unmatched'))->assertOk()->json('data');

        // Only the stranger: the other two are matched, and a guest is
        // somebody else's staff.
        $this->assertCount(1, $data);
        $this->assertSame('mary@kns.co.ke', $data[0]['upn']);
        $this->assertSame('Field Engineer', $data[0]['job_title']);
        // Split once, on the server, so the suggestion and the record agree.
        $this->assertSame('Mary', $data[0]['suggested']['first_name']);
        $this->assertSame('Atieno', $data[0]['suggested']['last_name']);
    }

    public function test_a_suggestion_becomes_an_employee_and_a_linked_login(): void
    {
        $this->actor();

        $body = $this->postJson($this->url('/authorization/directory/create-employee'), [
            'azure_id' => 'stranger', 'email' => 'mary@kns.co.ke',
            'first_name' => 'Mary', 'last_name' => 'Atieno',
        ])->assertStatus(201)->json('data');

        $this->assertDatabaseHas('employees', [
            'id' => $body['employee_id'], 'work_email' => 'mary@kns.co.ke',
        ]);

        $user = User::findOrFail($body['user_id']);
        $this->assertSame('stranger', $user->azure_id);
        $this->assertSame(User::AUTH_SSO, $user->auth_method);
        // Employee and login point at each other.
        $this->assertSame($user->id, Employee::findOrFail($body['employee_id'])->user_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'identity.imported_from_directory']);
    }

    public function test_a_service_account_can_be_recorded_without_a_login(): void
    {
        $this->actor();

        $body = $this->postJson($this->url('/authorization/directory/create-employee'), [
            'azure_id' => 'svc', 'email' => 'scanner@kns.co.ke',
            'first_name' => 'Reception Scanner', 'create_login' => false,
        ])->assertStatus(201)->json('data');

        // On the register without becoming somebody who can sign in here.
        $this->assertNull($body['user_id']);
        $this->assertDatabaseHas('employees', ['id' => $body['employee_id']]);
    }

    public function test_accepting_the_same_suggestion_twice_is_refused(): void
    {
        $this->actor();

        $payload = [
            'azure_id' => 'stranger', 'email' => 'mary@kns.co.ke', 'first_name' => 'Mary',
        ];

        $this->postJson($this->url('/authorization/directory/create-employee'), $payload)->assertStatus(201);

        // Two people can accept the same suggestion in different tabs.
        $this->postJson($this->url('/authorization/directory/create-employee'), $payload)->assertStatus(422);
    }

    // ── Authorization ─────────────────────────────────────────────────────

    public function test_provisioning_is_its_own_permission(): void
    {
        // Taking access away and handing it out are different risks.
        $this->actor([Permission::ROLES_VIEW, Permission::USERS_DEPROVISION]);
        $starter = User::factory()->create(['email' => 'jane@kns.co.ke']);

        $this->postJson($this->url("/authorization/users/{$starter->id}/provision"))->assertForbidden();
        $this->getJson($this->url('/authorization/directory/unmatched'))->assertForbidden();
        $this->postJson($this->url('/authorization/directory/create-employee'), [
            'azure_id' => 'x', 'email' => 'x@kns.co.ke', 'first_name' => 'X',
        ])->assertForbidden();
    }
}
