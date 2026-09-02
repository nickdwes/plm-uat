<?php

namespace Tests\Feature\Asset;

use App\Models\Asset\Asset;
use App\Models\Asset\AssetSeatAllocation;
use App\Models\Employee;
use App\Models\Integration\DirectoryAccount;
use App\Models\Integration\IntegrationConnection;
use App\Models\Integration\LicenceSku;
use App\Models\Organization;
use App\Models\User;
use App\Services\Integration\LicenceAssetImport;
use App\Services\Integration\LicenceOwnerMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Putting a name to every licence seat.
 *
 * The pieces existed and were unjoined: the sync writes a directory account
 * per licensed person and deliberately leaves `employee_id` alone, and the
 * import puts the SKUs into the register as products. Neither knew who held
 * what, so the register showed Microsoft's consumed count beside a seats-used
 * of nought.
 */
class LicenceOwnerMappingTest extends TestCase
{
    use RefreshDatabase;

    /** Real UUIDs: `integration_licence_skus.sku_id` is a uuid column. */
    private const E3   = '05e9a617-0261-4cee-bb44-138d3ef5d965';
    private const FREE = 'e0dfc8b9-9603-45ec-b5a0-3d9e9b0f45c1';

    private Organization $org;
    private IntegrationConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);

        $this->connection = IntegrationConnection::create([
            'organization_id' => $this->org->id,
            'name' => 'Tenant', 'provider' => 'microsoft',
            'tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's',
            'is_active' => true, 'sync_licences' => true,
        ]);
    }

    private function employee(string $first, ?string $workEmail, ?string $loginEmail = null): Employee
    {
        $user = $loginEmail ? User::factory()->create(['email' => $loginEmail]) : null;

        return Employee::create([
            'employee_number' => 'E' . fake()->unique()->numberBetween(1000, 999999),
            'organization_id' => $this->org->id,
            'primary_organization_id' => $this->org->id,
            'first_name' => $first, 'last_name' => 'Test',
            'work_email' => $workEmail, 'user_id' => $user?->id,
            'employment_type' => 'permanent', 'date_hired' => '2024-01-01',
        ]);
    }

    private function sku(string $guid, string $name = 'Microsoft 365 E3'): LicenceSku
    {
        return LicenceSku::create([
            'organization_id' => $this->org->id,
            'integration_connection_id' => $this->connection->id,
            'sku_id' => $guid, 'part_number' => 'E3', 'name' => $name,
            // Paid: the free and viral SKUs are the ones the import skips.
            'classification' => \App\Services\Integration\LicenceSkus::PAID,
        ]);
    }

    /** The licence product an import would have created for this SKU. */
    private function licenceAsset(string $guid, string $name = 'Microsoft 365 E3'): Asset
    {
        return Asset::create([
            'organization_id' => $this->org->id,
            'asset_tag' => 'LIC-' . fake()->unique()->numberBetween(1000, 999999),
            'name' => $name, 'asset_class' => Asset::CLASS_SOFTWARE,
            'category' => 'licence', 'status' => Asset::STATUS_IN_SERVICE,
            'external_source' => LicenceAssetImport::SOURCE,
            'external_ref' => 'sku:' . $guid,
        ]);
    }

    private function account(string $upn, LicenceSku $sku, ?string $mail = null): DirectoryAccount
    {
        $account = DirectoryAccount::create([
            'organization_id' => $this->org->id,
            'integration_connection_id' => $this->connection->id,
            // `external_id` is a uuid column: it holds the Entra object id.
            'external_id' => (string) \Illuminate\Support\Str::uuid(),
            'display_name' => $upn, 'user_principal_name' => $upn,
            'mail' => $mail ?? $upn, 'account_enabled' => true,
            'licence_count' => 1, 'is_present' => true,
        ]);

        $account->skus()->sync([$sku->id]);

        return $account;
    }

    private function mapper(): LicenceOwnerMapper
    {
        return app(LicenceOwnerMapper::class);
    }

    // ── Matching ──────────────────────────────────────────────────────────

    public function test_an_account_is_matched_to_the_employee_at_that_address(): void
    {
        $employee = $this->employee('Grace', 'grace@kns.co.ke');
        $account  = $this->account('grace@kns.co.ke', $this->sku(self::E3));

        $result = $this->mapper()->matchAccounts($this->connection);

        $this->assertSame(1, $result['matched']);
        $this->assertSame($employee->id, $account->fresh()->employee_id);
    }

    public function test_a_mailbox_that_differs_from_the_sign_in_name_still_matches(): void
    {
        // A second domain or a migrated account — the ordinary case here.
        $employee = $this->employee('Grace', 'grace@konvergenz.co.ug');
        $account  = $this->account('grace@kns.co.ke', $this->sku(self::E3), 'grace@konvergenz.co.ug');

        $this->mapper()->matchAccounts($this->connection);

        $this->assertSame($employee->id, $account->fresh()->employee_id);
    }

    public function test_the_login_address_is_tried_as_well_as_the_work_one(): void
    {
        $employee = $this->employee('Grace', null, 'grace@kns.co.ke');
        $account  = $this->account('grace@kns.co.ke', $this->sku(self::E3));

        $this->mapper()->matchAccounts($this->connection);

        $this->assertSame($employee->id, $account->fresh()->employee_id);
    }

    public function test_an_address_on_two_people_matches_neither(): void
    {
        // A shared mailbox, or a duplicate nobody has merged. Assigning it to
        // whichever row came back first is how somebody else's licence lands
        // on your record.
        $this->employee('Grace', 'shared@kns.co.ke');
        $this->employee('Peter', 'shared@kns.co.ke');
        $account = $this->account('shared@kns.co.ke', $this->sku(self::E3));

        $result = $this->mapper()->matchAccounts($this->connection);

        $this->assertSame(0, $result['matched']);
        $this->assertSame(1, $result['ambiguous']);
        $this->assertNull($account->fresh()->employee_id);
    }

    public function test_a_match_already_made_is_left_alone(): void
    {
        $employee = $this->employee('Grace', 'grace@kns.co.ke');
        $chosen   = $this->employee('Peter', 'peter@kns.co.ke');

        $account = $this->account('grace@kns.co.ke', $this->sku(self::E3));
        // Somebody corrected it by hand; a sync must not undo that.
        $account->forceFill(['employee_id' => $chosen->id])->save();

        $result = $this->mapper()->matchAccounts($this->connection);

        $this->assertSame(1, $result['already']);
        $this->assertSame($chosen->id, $account->fresh()->employee_id);
    }

    public function test_a_preview_matches_nothing(): void
    {
        $this->employee('Grace', 'grace@kns.co.ke');
        $account = $this->account('grace@kns.co.ke', $this->sku(self::E3));

        $result = $this->mapper()->matchAccounts($this->connection, commit: false);

        $this->assertSame(1, $result['matched']);
        $this->assertNull($account->fresh()->employee_id);
    }

    // ── Seats ─────────────────────────────────────────────────────────────

    public function test_a_seat_is_allocated_on_the_licence_the_person_holds(): void
    {
        $employee = $this->employee('Grace', 'grace@kns.co.ke');
        $sku      = $this->sku(self::E3);
        $asset    = $this->licenceAsset(self::E3);
        $this->account('grace@kns.co.ke', $sku);

        $result = $this->mapper()->run($this->connection);

        $this->assertSame(1, $result['seats']['allocated']);
        $this->assertDatabaseHas('asset_seat_allocations', [
            'asset_id' => $asset->id, 'employee_id' => $employee->id, 'released_on' => null,
        ]);
        // The register now agrees with itself.
        $this->assertSame(1, $asset->fresh()->seatsUsed());
    }

    public function test_running_it_twice_changes_nothing(): void
    {
        $this->employee('Grace', 'grace@kns.co.ke');
        $sku = $this->sku(self::E3);
        $this->licenceAsset(self::E3);
        $this->account('grace@kns.co.ke', $sku);

        $this->mapper()->run($this->connection);
        $second = $this->mapper()->run($this->connection);

        $this->assertSame(0, $second['seats']['allocated']);
        $this->assertSame(1, AssetSeatAllocation::count());
    }

    public function test_a_seat_the_directory_no_longer_reports_is_released(): void
    {
        $this->employee('Grace', 'grace@kns.co.ke');
        $sku     = $this->sku(self::E3);
        $asset   = $this->licenceAsset(self::E3);
        $account = $this->account('grace@kns.co.ke', $sku);

        $this->mapper()->run($this->connection);

        // Microsoft stops reporting the licence — they left, or it was moved.
        $account->skus()->sync([]);

        $result = $this->mapper()->run($this->connection);

        $this->assertSame(1, $result['seats']['released']);
        // Released, not deleted: what somebody held and when is the question
        // a licence audit asks.
        $this->assertSame(1, AssetSeatAllocation::count());
        $this->assertNotNull(AssetSeatAllocation::first()->released_on);
        $this->assertSame(0, $asset->fresh()->seatsUsed());
    }

    public function test_a_seat_allocated_by_hand_is_never_released_by_the_sync(): void
    {
        $employee = $this->employee('Grace', 'grace@kns.co.ke');
        $sku      = $this->sku(self::E3);
        $asset    = $this->licenceAsset(self::E3);
        $this->account('peter@kns.co.ke', $sku);

        // Somebody's decision, not the sync's.
        AssetSeatAllocation::create([
            'organization_id' => $this->org->id, 'asset_id' => $asset->id,
            'employee_id' => $employee->id, 'allocated_on' => now()->toDateString(),
            'note' => 'Allocated by IT for the pilot',
        ]);

        $result = $this->mapper()->run($this->connection);

        $this->assertSame(0, $result['seats']['released']);
        $this->assertNull(AssetSeatAllocation::first()->released_on);
    }

    public function test_a_seat_nobody_here_matches_is_counted_not_hidden(): void
    {
        $sku = $this->sku(self::E3);
        $this->licenceAsset(self::E3);
        // Nobody on the employee register holds this address.
        $this->account('stranger@kns.co.ke', $sku);

        $result = $this->mapper()->run($this->connection);

        // The gap between Microsoft's consumed figure and ours stays visible.
        $this->assertSame(0, $result['seats']['allocated']);
        $this->assertSame(1, $result['seats']['unmatched_seats']);
    }

    public function test_a_sku_with_no_licence_asset_is_skipped_quietly(): void
    {
        $this->employee('Grace', 'grace@kns.co.ke');
        // A free or viral SKU the import deliberately leaves out.
        $this->account('grace@kns.co.ke', $this->sku(self::FREE, 'Windows Store'));

        $result = $this->mapper()->run($this->connection);

        $this->assertSame(0, $result['seats']['allocated']);
        $this->assertSame(0, $result['seats']['unmatched_seats']);
    }

    public function test_a_preview_before_the_first_import_still_says_how_many_seats(): void
    {
        $this->employee('Grace', 'grace@kns.co.ke');
        $this->account('grace@kns.co.ke', $this->sku(self::E3));
        // Deliberately no licence asset: this is a first run.

        // Through run(), which is how a preview is actually taken: the match
        // step hands its projection to the seat step.
        $result = $this->mapper()->run($this->connection, commit: false)['seats'];

        /*
         * Otherwise the preview reports nought and the commit then allocates
         * hundreds — the exact surprise a preview exists to prevent.
         */
        $this->assertSame(1, $result['allocated']);
        $this->assertTrue($result['estimated']);
        $this->assertSame(0, AssetSeatAllocation::count());
    }

    // ── The action on the Assets screen ───────────────────────────────────

    public function test_the_assets_screen_refuses_when_no_connection_reads_licences(): void
    {
        $this->connection->update(['sync_licences' => false]);

        $user = User::factory()->create();
        $role = \App\Models\Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'T', 'scope' => 'organization',
            'permissions' => ['assets.view', 'assets.update'],
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        \Laravel\Sanctum\Sanctum::actingAs($user);

        // Says what to do about it rather than failing obscurely.
        $this->postJson("/api/v1/assets/sync-licences?org={$this->org->slug}")
            ->assertStatus(422)
            ->assertSee('Integrations', false);
    }

    public function test_syncing_licences_needs_more_than_read_access(): void
    {
        $user = User::factory()->create();
        $role = \App\Models\Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'T', 'scope' => 'organization',
            'permissions' => ['assets.view'],
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        \Laravel\Sanctum\Sanctum::actingAs($user);

        $this->postJson("/api/v1/assets/sync-licences?org={$this->org->slug}")->assertForbidden();
    }
}
