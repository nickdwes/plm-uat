<?php

namespace Tests\Feature\Integration;

use App\Models\Integration\IntegrationConnection;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The tenants the register is allowed to read.
 *
 * Most of what matters here is invisible in a response body: that the secret
 * never comes back out, that editing a name does not wipe it, and that being
 * trusted with the asset register is not the same as being trusted with a
 * credential that can read a whole directory.
 */
class IntegrationConnectionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
    }

    private function signIn(array $permissions): User
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
        Sanctum::actingAs($user);

        return $user;
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/integrations/connections{$path}?org={$this->org->slug}";
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name'          => 'Konvergenz — Microsoft 365',
            'tenant_id'     => 'fe3cf4ea-a68e-4f54-b968-50e80b84f4a7',
            'client_id'     => '9b88a83b-5918-431d-bd41-edbc9ba0e9f4',
            'client_secret' => 'a-real-looking-secret',
            'sync_licences' => true,
            'sync_azure'    => true,
        ], $overrides);
    }

    private function connection(array $overrides = []): IntegrationConnection
    {
        return IntegrationConnection::create(array_merge([
            'organization_id' => $this->org->id,
            'name'            => 'Konvergenz — Microsoft 365',
            'tenant_id'       => 'fe3cf4ea-a68e-4f54-b968-50e80b84f4a7',
            'client_id'       => '9b88a83b-5918-431d-bd41-edbc9ba0e9f4',
            'client_secret'   => 'a-real-looking-secret',
        ], $overrides));
    }

    // ── Secrets ───────────────────────────────────────────────────────────────

    /**
     * The screen that sets a secret has no business reading one back.
     *
     * Otherwise a single XSS on this page is a stolen tenant credential, and
     * that credential can read the whole directory.
     */
    public function test_the_client_secret_never_comes_back_out(): void
    {
        $this->signIn(['integrations.manage']);

        $create = $this->postJson($this->url(), $this->payload())->assertCreated();
        $this->assertStringNotContainsString('a-real-looking-secret', $create->getContent());
        $create->assertJsonPath('data.has_secret', true);

        $list = $this->getJson($this->url())->assertOk();
        $this->assertStringNotContainsString('a-real-looking-secret', $list->getContent());
    }

    public function test_the_secret_is_encrypted_at_rest(): void
    {
        $connection = $this->connection();

        $stored = \DB::table('integration_connections')->where('id', $connection->id)->value('client_secret');

        $this->assertNotSame('a-real-looking-secret', $stored);
        $this->assertSame('a-real-looking-secret', $connection->fresh()->client_secret);
    }

    /**
     * Editing the name must not break the connection.
     *
     * The form cannot show the current secret — it is never sent — so an edit
     * that only changes the name submits a blank secret field. Taking that
     * literally would clear the credential every time somebody fixed a typo.
     */
    public function test_an_update_without_a_secret_keeps_the_existing_one(): void
    {
        $this->signIn(['integrations.manage']);
        $connection = $this->connection();

        $this->putJson($this->url("/{$connection->id}"), $this->payload([
            'name'          => 'Konvergenz — renamed',
            'client_secret' => '',
        ]))->assertOk()->assertJsonPath('data.has_secret', true);

        $connection->refresh();
        $this->assertSame('Konvergenz — renamed', $connection->name);
        $this->assertSame('a-real-looking-secret', $connection->client_secret);
    }

    public function test_a_supplied_secret_replaces_the_old_one(): void
    {
        $this->signIn(['integrations.manage']);
        $connection = $this->connection();

        $this->putJson($this->url("/{$connection->id}"), $this->payload([
            'client_secret' => 'the-rotated-one',
        ]))->assertOk();

        $this->assertSame('the-rotated-one', $connection->fresh()->client_secret);
    }

    // ── Who may ───────────────────────────────────────────────────────────────

    /**
     * Maintaining the asset register is not the same job as holding tenant
     * credentials, and the two permissions are deliberately separate.
     */
    public function test_asset_permissions_do_not_grant_connection_management(): void
    {
        $this->signIn(['assets.view', 'assets.create', 'assets.update']);

        $this->getJson($this->url())->assertForbidden();
        $this->postJson($this->url(), $this->payload())->assertForbidden();
    }

    public function test_viewing_does_not_grant_editing(): void
    {
        $this->signIn(['integrations.view']);
        $connection = $this->connection();

        $this->getJson($this->url())->assertOk();
        $this->putJson($this->url("/{$connection->id}"), $this->payload())->assertForbidden();
        $this->deleteJson($this->url("/{$connection->id}"))->assertForbidden();
    }

    // ── Shape ─────────────────────────────────────────────────────────────────

    /** The same directory twice would import every licence twice. */
    public function test_a_tenant_cannot_be_registered_twice_in_one_organization(): void
    {
        $this->signIn(['integrations.manage']);
        $this->connection();

        $this->postJson($this->url(), $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('tenant_id');
    }

    /**
     * "The tenant id has already been taken" is true and a dead end.
     *
     * Hitting this means the connection is already there — usually the one
     * already syncing — so the message names it and says to edit that instead.
     */
    public function test_the_duplicate_message_names_the_connection_already_there(): void
    {
        $this->signIn(['integrations.manage']);
        $this->connection(['name' => 'Konvergenz — Microsoft 365']);

        $response = $this->postJson($this->url(), $this->payload())->assertStatus(422);

        $message = $response->json('errors.tenant_id.0');

        $this->assertStringContainsString('Konvergenz — Microsoft 365', $message);
        $this->assertStringContainsString('Edit that one instead', $message);
    }

    public function test_the_same_tenant_may_serve_a_different_organization(): void
    {
        $other = Organization::create(['name' => 'Beta', 'slug' => 'beta']);
        $this->connection(['organization_id' => $other->id]);

        $this->signIn(['integrations.manage']);

        $this->postJson($this->url(), $this->payload())->assertCreated();
    }

    // ── Subscriptions ─────────────────────────────────────────────────────────

    /**
     * What a principal can reach is not what belongs in this register.
     *
     * Reader granted high in the management group tree can see subscriptions
     * belonging to other parts of the business; importing those is how one
     * company's asset list quietly acquires another's servers.
     */
    public function test_discovered_subscriptions_arrive_switched_off(): void
    {
        $this->signIn(['integrations.manage']);
        $connection = $this->connection(['sync_azure' => true]);

        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => $this->fakeToken()]),
            'management.azure.com/subscriptions?*' => Http::response(['value' => [
                ['subscriptionId' => '45507162-7883-416f-9efa-d41d08340e1d', 'displayName' => 'Sponsorship', 'state' => 'Enabled'],
                ['subscriptionId' => '11111111-2222-3333-4444-555555555555', 'displayName' => 'Somebody else', 'state' => 'Enabled'],
            ]]),
        ]);

        $this->postJson($this->url("/{$connection->id}/discover"))
            ->assertOk()
            ->assertJsonCount(2, 'data.subscriptions');

        $this->assertSame(0, $connection->subscriptions()->where('is_enabled', true)->count());
    }

    public function test_enabling_subscriptions_replaces_the_selection(): void
    {
        $this->signIn(['integrations.manage']);
        $connection = $this->connection(['sync_azure' => true]);

        $ids = [
            '11111111-1111-1111-1111-111111111111',
            '22222222-2222-2222-2222-222222222222',
            '33333333-3333-3333-3333-333333333333',
        ];

        foreach ($ids as $id) {
            $connection->subscriptions()->create([
                'subscription_id' => $id, 'display_name' => $id, 'is_enabled' => true,
            ]);
        }

        $this->putJson($this->url("/{$connection->id}/subscriptions"), ['enabled' => [$ids[1]]])
            ->assertOk();

        $this->assertSame([$ids[1]], $connection->enabledSubscriptionIds());
    }

    /**
     * Re-running discovery to pick up a new subscription must not switch off
     * the ones already syncing.
     */
    public function test_rediscovery_leaves_existing_choices_alone(): void
    {
        $this->signIn(['integrations.manage']);
        $connection = $this->connection(['sync_azure' => true]);
        $connection->subscriptions()->create([
            'subscription_id' => '45507162-7883-416f-9efa-d41d08340e1d',
            'display_name'    => 'Sponsorship',
            'is_enabled'      => true,
        ]);

        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => $this->fakeToken()]),
            'management.azure.com/subscriptions?*' => Http::response(['value' => [
                ['subscriptionId' => '45507162-7883-416f-9efa-d41d08340e1d', 'displayName' => 'Sponsorship', 'state' => 'Enabled'],
                ['subscriptionId' => '99999999-2222-3333-4444-555555555555', 'displayName' => 'New one', 'state' => 'Enabled'],
            ]]),
        ]);

        $this->postJson($this->url("/{$connection->id}/discover"))->assertOk();

        $this->assertSame(['45507162-7883-416f-9efa-d41d08340e1d'], $connection->enabledSubscriptionIds());
    }

    // ── Testing credentials ───────────────────────────────────────────────────

    /**
     * "Connected" is not the useful answer.
     *
     * A tenant with the licence roles and no ARM Reader looks identical to a
     * working one until an Azure sync 403s, by which time whoever granted
     * consent has moved on.
     */
    public function test_a_credential_test_records_which_permissions_were_granted(): void
    {
        $this->signIn(['integrations.manage']);
        $connection = $this->connection(['sync_azure' => false]);

        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => $this->fakeToken([
                'Organization.Read.All', 'User.Read.All',
            ])]),
            'graph.microsoft.com/v1.0/subscribedSkus' => Http::response(['value' => [
                ['skuId' => 'x', 'skuPartNumber' => 'SPE_E3', 'consumedUnits' => 2,
                 'prepaidUnits' => ['enabled' => 10], 'capabilityStatus' => 'Enabled'],
            ]]),
            'graph.microsoft.com/v1.0/users*' => Http::response(['value' => []]),
        ]);

        $this->postJson($this->url("/{$connection->id}/test"))
            ->assertOk()
            ->assertJsonPath('roles', ['Organization.Read.All', 'User.Read.All'])
            ->assertJsonPath('checks.licences.ok', true);

        $this->assertSame(IntegrationConnection::STATUS_OK, $connection->fresh()->last_test_status);
    }

    /**
     * A Graph 403 and an ARM 403 are fixed in different places by different
     * people, and the message is the only thing that distinguishes them.
     */
    public function test_a_refused_credential_is_reported_in_terms_of_what_to_fix(): void
    {
        $this->signIn(['integrations.manage']);
        $connection = $this->connection();

        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => $this->fakeToken()]),
            'graph.microsoft.com/*' => Http::response([
                'error' => ['code' => 'Authorization_RequestDenied', 'message' => 'Insufficient privileges.'],
            ], 403),
        ]);

        $response = $this->postJson($this->url("/{$connection->id}/test"))
            ->assertOk()
            ->assertJsonPath('checks.licences.ok', false);

        $this->assertStringContainsString('admin consent', $response->json('message'));
        $this->assertSame(IntegrationConnection::STATUS_FAILED, $connection->fresh()->last_test_status);
    }

    /** A JWT whose payload carries the roles claim, which is all we read. */
    private function fakeToken(array $roles = ['Organization.Read.All']): string
    {
        $payload = rtrim(strtr(base64_encode(json_encode(['roles' => $roles])), '+/', '-_'), '=');

        return "header.{$payload}.signature";
    }
}
