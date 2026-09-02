<?php

namespace Tests\Feature\Authorization;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\Account\DefaultReferenceData;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Which employee each login belongs to.
 *
 * `employees.user_id` is what every "own" scope resolves through — my leads,
 * my tasks, my leave. A login with no employee behind it can hold every
 * permission in the system and still see an empty screen. These prove the link
 * can be seen, set and moved, and that it cannot be claimed twice.
 */
class UserDirectoryTest extends TestCase
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
        $user = User::factory()->create(['name' => 'Admin One']);
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'Test', 'scope' => 'organization', 'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);

        return $user;
    }

    private function employee(array $attributes = []): Employee
    {
        return Employee::create(array_merge([
            'employee_number' => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'primary_organization_id' => $this->org->id,
            'first_name' => 'Grace', 'last_name' => 'Muli',
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
            'status' => 'active',
        ], $attributes));
    }

    private function actorId(): int
    {
        return \Illuminate\Support\Facades\Auth::id() ?? auth()->id();
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/authorization/users{$path}?org={$this->org->slug}";
    }

    // ── Reading ───────────────────────────────────────────────────────────

    public function test_the_directory_shows_which_employee_each_login_is(): void
    {
        $this->actor([Permission::ROLES_VIEW]);

        $person = User::factory()->create(['name' => 'Grace Muli', 'email' => 'grace@alpha.example']);
        $this->employee(['user_id' => $person->id, 'work_email' => 'grace@alpha.example']);

        $row = collect($this->getJson($this->url())->assertOk()->json('data'))
            ->firstWhere('id', $person->id);

        $this->assertSame('Grace Muli', $row['employee']['name']);
        $this->assertTrue($row['email_matches']);
    }

    public function test_a_login_with_no_employee_behind_it_is_named(): void
    {
        // It can hold every permission in the system and still see an empty
        // screen, because every "own" scope resolves to nobody.
        $this->actor([Permission::ROLES_VIEW]);
        $orphan = User::factory()->create(['name' => 'Nobody']);

        $row = collect($this->getJson($this->url())->assertOk()->json('data'))
            ->firstWhere('id', $orphan->id);

        $this->assertNull($row['employee']);
    }

    public function test_employees_who_cannot_log_in_are_offered_alongside(): void
    {
        // "Who is missing" is the same question as "who is here", asked from
        // the other side — two screens would let the answers drift apart.
        $this->actor([Permission::ROLES_VIEW]);
        $this->employee(['first_name' => 'Unreachable']);

        $res = $this->getJson($this->url())->assertOk();

        $this->assertContains('Unreachable Muli', array_column($res->json('unlinked_employees'), 'name'));
        $this->assertSame(1, $res->json('totals.employees_no_login'));
    }

    public function test_an_address_that_disagrees_with_the_employee_record_is_flagged(): void
    {
        // Not necessarily wrong — people use personal addresses — but it is
        // the thing to look at when somebody says the wrong name appears.
        $this->actor([Permission::ROLES_VIEW]);

        $person = User::factory()->create(['email' => 'personal@gmail.example']);
        $this->employee(['user_id' => $person->id, 'work_email' => 'grace@alpha.example']);

        $row = collect($this->getJson($this->url())->assertOk()->json('data'))
            ->firstWhere('id', $person->id);

        $this->assertFalse($row['email_matches']);
    }

    public function test_a_login_with_an_employee_but_no_role_is_counted(): void
    {
        // Login → employee → role. A login joined to an employee but to no
        // role signs in successfully and can then do nothing, which is harder
        // to diagnose than not being able to sign in at all.
        $this->actor([Permission::ROLES_VIEW]);

        $person = User::factory()->create();
        $this->employee(['user_id' => $person->id]);

        $res = $this->getJson($this->url())->assertOk();

        // The actor holds a role; the person we just made does not.
        $this->assertSame(1, $res->json('totals.without_role'));
    }

    // ── Linking ───────────────────────────────────────────────────────────

    public function test_a_login_can_be_matched_to_an_employee(): void
    {
        $this->actor([Permission::ROLES_VIEW, Permission::ROLES_ASSIGN]);

        $person = User::factory()->create();
        $employee = $this->employee();

        $res = $this->putJson($this->url("/{$person->id}/employee"), ['employee_id' => $employee->id])
            ->assertOk();

        $this->assertSame($employee->id, $res->json('data.employee.id'));
        $this->assertSame($person->id, $employee->fresh()->user_id);
    }

    public function test_a_login_can_be_detached(): void
    {
        $this->actor([Permission::ROLES_VIEW, Permission::ROLES_ASSIGN]);

        $person = User::factory()->create();
        $employee = $this->employee(['user_id' => $person->id]);

        $this->putJson($this->url("/{$person->id}/employee"), ['employee_id' => null])->assertOk();

        $this->assertNull($employee->fresh()->user_id);
    }

    public function test_an_employee_record_already_taken_is_refused_by_name(): void
    {
        // A constraint violation is a 500 with a Postgres string in it; the
        // person doing this deserves to be told which login already holds it.
        $this->actor([Permission::ROLES_VIEW, Permission::ROLES_ASSIGN]);

        $first  = User::factory()->create(['email' => 'first@alpha.example']);
        $second = User::factory()->create();
        $employee = $this->employee(['user_id' => $first->id]);

        $res = $this->putJson($this->url("/{$second->id}/employee"), ['employee_id' => $employee->id])
            ->assertStatus(422)->assertJsonValidationErrors('employee_id');

        $this->assertStringContainsString('first@alpha.example', $res->json('errors.employee_id.0'));
        $this->assertSame($first->id, $employee->fresh()->user_id);
    }

    public function test_moving_a_login_to_another_employee_releases_the_first(): void
    {
        // Otherwise the login is claimed twice and "my employee record" has
        // two answers.
        $this->actor([Permission::ROLES_VIEW, Permission::ROLES_ASSIGN]);

        $person = User::factory()->create();
        $wrong  = $this->employee(['user_id' => $person->id, 'first_name' => 'Wrong']);
        $right  = $this->employee(['first_name' => 'Right']);

        $this->putJson($this->url("/{$person->id}/employee"), ['employee_id' => $right->id])->assertOk();

        $this->assertNull($wrong->fresh()->user_id);
        $this->assertSame($person->id, $right->fresh()->user_id);
    }

    // ── Discovery and the two paths ───────────────────────────────────────

    private function fakeGraph(array $stub): void
    {
        \Illuminate\Support\Facades\Http::fake([
            'login.microsoftonline.com/*' => \Illuminate\Support\Facades\Http::response(['access_token' => 'tok'], 200),
            'graph.microsoft.com/*'       => $stub['response'],
        ]);
    }

    private function tenant(): void
    {
        $conn = \App\Models\Integration\IntegrationConnection::create([
            'organization_id' => $this->org->id, 'name' => 'MS', 'provider' => 'microsoft',
            'tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'is_active' => true,
        ]);
        // Named for directory work specifically. Discovery no longer takes
        // whichever Microsoft connection it finds first.
        $this->org->update([
            'calendar_connection_id' => $conn->id,
            'identity_connection_id' => $conn->id,
        ]);
    }

    public function test_somebody_active_in_office_365_is_put_on_the_sso_path(): void
    {
        $this->actor([Permission::ROLES_VIEW, Permission::ROLES_ASSIGN]);
        $this->tenant();

        $person = User::factory()->create(['email' => 'grace@alpha.example']);
        $this->fakeGraph(['response' => \Illuminate\Support\Facades\Http::response(
            ['id' => 'oid-123', 'accountEnabled' => true, 'userPrincipalName' => 'grace@alpha.example'], 200,
        )]);

        $res = $this->postJson($this->url("/{$person->id}/discover"))->assertOk();

        $this->assertSame(User::AUTH_SSO, $res->json('data.auth.method'));
        $this->assertSame(User::DISCOVERY_ENABLED, $res->json('data.auth.discovery.status'));
        // The immutable object id, not the display name.
        $this->assertSame('oid-123', $person->fresh()->azure_id);
    }

    public function test_somebody_office_365_has_never_heard_of_goes_local(): void
    {
        $this->actor([Permission::ROLES_VIEW, Permission::ROLES_ASSIGN]);
        $this->tenant();

        $person = User::factory()->create();

        // The direct path 404s and the mail search comes back empty, which is
        // what Graph actually does — the collection endpoint answers 200 with
        // no rows rather than 404.
        \Illuminate\Support\Facades\Http::fake([
            'login.microsoftonline.com/*' => \Illuminate\Support\Facades\Http::response(['access_token' => 'tok'], 200),
            'graph.microsoft.com/v1.0/users?*' => \Illuminate\Support\Facades\Http::response(['value' => []], 200),
            'graph.microsoft.com/v1.0/users/*'  => \Illuminate\Support\Facades\Http::response(['error' => ['code' => 'NotFound']], 404),
        ]);

        $res = $this->postJson($this->url("/{$person->id}/discover"))->assertOk();

        $this->assertSame(User::AUTH_LOCAL_TOTP, $res->json('data.auth.method'));
        $this->assertSame(User::DISCOVERY_MISSING, $res->json('data.auth.discovery.status'));
        // A method is not an enrolment: nothing can sign in with this yet.
        $this->assertTrue($res->json('data.auth.pending_enrollment'));
    }

    public function test_somebody_signed_in_under_another_name_is_still_found(): void
    {
        // `GET /users/{key}` takes an object id or a userPrincipalName and
        // nothing else, so a person reachable at x@second-domain but signing in
        // as x@main-domain 404s from the direct path while existing perfectly
        // well. Five of the eight "not found" on this tenant were that shape.
        $this->actor([Permission::ROLES_VIEW, Permission::ROLES_ASSIGN]);
        $this->tenant();

        $person = User::factory()->create(['email' => 'erina@second.example']);

        \Illuminate\Support\Facades\Http::fake([
            'login.microsoftonline.com/*' => \Illuminate\Support\Facades\Http::response(['access_token' => 'tok'], 200),
            // The direct path misses…
            'graph.microsoft.com/v1.0/users/erina*' => \Illuminate\Support\Facades\Http::response([], 404),
            // …and the mail search finds them.
            'graph.microsoft.com/v1.0/users*' => \Illuminate\Support\Facades\Http::response([
                'value' => [['id' => 'oid-77', 'accountEnabled' => true, 'mail' => 'erina@second.example']],
            ], 200),
        ]);

        $res = $this->postJson($this->url("/{$person->id}/discover"))->assertOk();

        $this->assertSame(User::AUTH_SSO, $res->json('data.auth.method'));
        $this->assertSame('oid-77', $person->fresh()->azure_id);
    }

    public function test_an_address_on_two_accounts_names_neither(): void
    {
        // Picking one would be a guess that decides how somebody authenticates.
        $this->actor([Permission::ROLES_VIEW, Permission::ROLES_ASSIGN]);
        $this->tenant();

        $person = User::factory()->create(['email' => 'shared@second.example']);

        \Illuminate\Support\Facades\Http::fake([
            'login.microsoftonline.com/*' => \Illuminate\Support\Facades\Http::response(['access_token' => 'tok'], 200),
            'graph.microsoft.com/v1.0/users/shared*' => \Illuminate\Support\Facades\Http::response([], 404),
            'graph.microsoft.com/v1.0/users*' => \Illuminate\Support\Facades\Http::response([
                'value' => [
                    ['id' => 'oid-1', 'accountEnabled' => true],
                    ['id' => 'oid-2', 'accountEnabled' => true],
                ],
            ], 200),
        ]);

        $res = $this->postJson($this->url("/{$person->id}/discover"))->assertOk();

        $this->assertSame(User::DISCOVERY_MISSING, $res->json('data.auth.discovery.status'));
        $this->assertStringContainsString('More than one', $res->json('data.auth.discovery.error'));
        $this->assertNull($person->fresh()->azure_id);
    }

    public function test_found_but_disabled_is_recorded_as_its_own_answer(): void
    {
        // Not found and found-but-disabled both mean local, but one is a
        // person who never had an account and the other is a leaver.
        $this->actor([Permission::ROLES_VIEW, Permission::ROLES_ASSIGN]);
        $this->tenant();

        $person = User::factory()->create();
        $this->fakeGraph(['response' => \Illuminate\Support\Facades\Http::response(
            ['id' => 'oid-9', 'accountEnabled' => false], 200,
        )]);

        $res = $this->postJson($this->url("/{$person->id}/discover"))->assertOk();

        $this->assertSame(User::DISCOVERY_DISABLED, $res->json('data.auth.discovery.status'));
        $this->assertSame(User::AUTH_LOCAL_TOTP, $res->json('data.auth.method'));
    }

    public function test_a_refused_lookup_is_not_mistaken_for_an_answer(): void
    {
        // A 403 for a missing app permission would otherwise read as "nobody
        // is in Office 365" and move the whole organisation onto the local
        // path on the strength of our own misconfiguration.
        $this->actor([Permission::ROLES_VIEW, Permission::ROLES_ASSIGN]);
        $this->tenant();

        $person = User::factory()->create(['auth_method' => User::AUTH_SSO]);
        $this->fakeGraph(['response' => \Illuminate\Support\Facades\Http::response(
            ['error' => ['message' => 'Insufficient privileges']], 403,
        )]);

        $res = $this->postJson($this->url("/{$person->id}/discover"))->assertOk();

        $this->assertSame(User::DISCOVERY_ERROR, $res->json('data.auth.discovery.status'));
        // Whatever was decided before still stands.
        $this->assertSame(User::AUTH_SSO, $res->json('data.auth.method'));
        $this->assertStringContainsString('User.Read.All', $res->json('data.auth.discovery.error'));
    }

    public function test_discovery_without_a_named_connection_says_so_rather_than_guessing(): void
    {
        // It used to take the first active Microsoft connection it found —
        // fine with one, wrong the moment there are two, and the wrong one
        // here would be the registration that reads every mailbox.
        $this->actor([Permission::ROLES_VIEW, Permission::ROLES_ASSIGN]);

        \App\Models\Integration\IntegrationConnection::create([
            'organization_id' => $this->org->id, 'name' => 'Support', 'provider' => 'microsoft',
            'tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'is_active' => true,
        ]);

        $res = $this->postJson($this->url("/{$this->actorId()}/discover"))->assertStatus(422);

        $this->assertStringContainsString('No connection has been named for directory lookups', $res->json('message'));
    }

    public function test_an_administrator_may_override_the_discovered_method(): void
    {
        // Discovery can be behind — a mailbox created this morning, a tenant
        // mid-migration — and somebody who can see the tenant is better placed
        // than a cached lookup.
        $this->actor([Permission::ROLES_VIEW, Permission::ROLES_ASSIGN]);

        $person = User::factory()->create(['auth_method' => User::AUTH_LOCAL_TOTP]);

        $res = $this->putJson($this->url("/{$person->id}/auth-method"), [
            'auth_method' => User::AUTH_SSO,
        ])->assertOk();

        $this->assertSame(User::AUTH_SSO, $res->json('data.auth.method'));
        $this->assertTrue($person->fresh()->sso_only);
    }

    public function test_two_people_cannot_share_a_local_username(): void
    {
        $this->actor([Permission::ROLES_VIEW, Permission::ROLES_ASSIGN]);

        $first  = User::factory()->create(['alt_username' => 'j.otieno']);
        $second = User::factory()->create();

        $this->putJson($this->url("/{$second->id}/auth-method"), [
            'auth_method' => User::AUTH_LOCAL_TOTP, 'alt_username' => 'j.otieno',
        ])->assertStatus(422)->assertJsonValidationErrors('alt_username');

        $this->assertSame('j.otieno', $first->fresh()->alt_username);
    }

    public function test_switching_method_needs_more_than_reading(): void
    {
        $this->actor([Permission::ROLES_VIEW]);

        $this->putJson($this->url("/{$this->actorId()}/auth-method"), ['auth_method' => User::AUTH_SSO])
            ->assertForbidden();
    }

    public function test_an_sso_account_has_no_password_here_to_reset(): void
    {
        // Entra holds it. Sending a link anyway would change a credential the
        // login does not use — worse than refusing, because it looks like it
        // worked.
        $this->actor([Permission::ROLES_VIEW, Permission::ROLES_ASSIGN]);

        $person = User::factory()->create(['auth_method' => User::AUTH_SSO]);

        $res = $this->postJson($this->url("/{$person->id}/reset-password"))
            ->assertStatus(422)->assertJsonValidationErrors('auth_method');

        $this->assertStringContainsString('managed there', $res->json('errors.auth_method.0'));
    }

    public function test_a_local_account_can_be_sent_a_reset_link(): void
    {
        $this->actor([Permission::ROLES_VIEW, Permission::ROLES_ASSIGN]);

        $person = User::factory()->create(['auth_method' => User::AUTH_LOCAL_TOTP]);

        $this->postJson($this->url("/{$person->id}/reset-password"))
            ->assertOk()->assertJsonPath('message', "A reset link is on its way to {$person->email}.");
    }

    // ── Permission ────────────────────────────────────────────────────────

    public function test_reading_the_directory_needs_permission(): void
    {
        $this->actor([]);

        $this->getJson($this->url())->assertForbidden();
    }

    public function test_matching_a_login_to_an_employee_needs_more_than_reading(): void
    {
        // It decides whose records a person sees as their own — the same kind
        // of decision as granting a role, and a larger one than reading.
        $this->actor([Permission::ROLES_VIEW]);

        $person = User::factory()->create();

        $this->putJson($this->url("/{$person->id}/employee"), ['employee_id' => $this->employee()->id])
            ->assertForbidden();
    }
}
