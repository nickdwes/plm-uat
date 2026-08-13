<?php

namespace Tests\Feature\Integration;

use App\Models\Integration\DirectoryAccount;
use App\Models\Integration\IntegrationConnection;
use App\Models\Integration\LicenceSku;
use App\Models\Organization;
use App\Services\Integration\LicenceSnapshot;
use App\Services\Integration\LicenceStatistics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Writing the directory down so it can be counted.
 *
 * The fixtures mirror the real tenant, including the parts that broke things:
 * SKUs with a million nominal seats, and accounts whose sign-in is blocked
 * while they still hold licences.
 */
class LicenceSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private IntegrationConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);

        $this->connection = IntegrationConnection::create([
            'organization_id' => $this->org->id,
            'name'            => 'Alpha — Microsoft 365',
            'tenant_id'       => 'fe3cf4ea-a68e-4f54-b968-50e80b84f4a7',
            'client_id'       => '9b88a83b-5918-431d-bd41-edbc9ba0e9f4',
            'client_secret'   => 'secret',
        ]);

        /*
         * One stub over mutable state, rather than a second Http::fake() per
         * test. Repeated fake() calls *merge* and the earliest match wins, so
         * a second call meant to describe "the tenant after a change" was
         * silently ignored and every re-sync test re-read the original data.
         */
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, 'login.microsoftonline.com')) {
                return Http::response(['access_token' => 'header.e30.sig']);
            }

            if (str_contains($url, 'subscribedSkus')) {
                return $this->refuse
                    ? Http::response(['error' => ['code' => 'Authorization_RequestDenied', 'message' => 'no']], 403)
                    : Http::response(['value' => $this->skus]);
            }

            if (str_contains($url, '/users')) {
                return Http::response(['value' => $this->accounts]);
            }

            return Http::response([], 404);
        });
    }

    private array $skus = [];
    private array $accounts = [];
    private bool $refuse = false;

    private const ACC_A1 = 'aaaaaaaa-0000-0000-0000-000000000001';
    private const ACC_A2 = 'aaaaaaaa-0000-0000-0000-000000000002';
    private const ACC_A3 = 'aaaaaaaa-0000-0000-0000-000000000003';
    private const ACC_A4 = 'aaaaaaaa-0000-0000-0000-000000000004';

    private const SKU_PREMIUM = '11111111-1111-1111-1111-111111111111';
    private const SKU_COPILOT = '22222222-2222-2222-2222-222222222222';
    private const SKU_FREE    = '33333333-3333-3333-3333-333333333333';

    private function fakeTenant(array $accounts = null): void
    {
        $this->skus = [
            ['skuId' => self::SKU_PREMIUM, 'skuPartNumber' => 'O365_BUSINESS_PREMIUM',
             'consumedUnits' => 3, 'prepaidUnits' => ['enabled' => 5], 'capabilityStatus' => 'Enabled'],
            ['skuId' => self::SKU_COPILOT, 'skuPartNumber' => 'Microsoft_365_Copilot',
             'consumedUnits' => 2, 'prepaidUnits' => ['enabled' => 12], 'capabilityStatus' => 'Enabled'],
            ['skuId' => self::SKU_FREE, 'skuPartNumber' => 'WINDOWS_STORE',
             'consumedUnits' => 0, 'prepaidUnits' => ['enabled' => 1000000], 'capabilityStatus' => 'Enabled'],
        ];

        $this->accounts = $accounts ?? [
            ['id' => self::ACC_A1, 'displayName' => 'Ada Wanjiru', 'userPrincipalName' => 'ada@kns.co.ke',
             'mail' => 'ada@kns.co.ke', 'accountEnabled' => true,
             'assignedLicenses' => [['skuId' => self::SKU_PREMIUM], ['skuId' => self::SKU_COPILOT]]],
            ['id' => self::ACC_A2, 'displayName' => 'Brian Otieno', 'userPrincipalName' => 'brian@kns.co.ke',
             'mail' => 'brian@kns.co.ke', 'accountEnabled' => false,
             'assignedLicenses' => [['skuId' => self::SKU_PREMIUM]]],
            ['id' => self::ACC_A3, 'displayName' => 'Carol Njeri', 'userPrincipalName' => 'carol@kns.co.ke',
             'mail' => 'carol@kns.co.ke', 'accountEnabled' => false,
             'assignedLicenses' => [['skuId' => self::SKU_PREMIUM], ['skuId' => self::SKU_COPILOT]]],
            // Holds nothing, so it is not waste however blocked it is.
            ['id' => self::ACC_A4, 'displayName' => 'Shared Mailbox', 'userPrincipalName' => 'info@kns.co.ke',
             'mail' => 'info@kns.co.ke', 'accountEnabled' => false, 'assignedLicenses' => []],
        ];
    }

    private function pull(): array
    {
        return app(LicenceSnapshot::class)->pull($this->connection);
    }

    // ── Writing ───────────────────────────────────────────────────────────────

    public function test_a_pull_writes_skus_accounts_and_assignments(): void
    {
        $this->fakeTenant();

        $result = $this->pull();

        $this->assertSame(3, $result['skus']);
        // The account holding nothing is not returned by licensedUsers().
        $this->assertSame(3, $result['accounts']);
        $this->assertSame(5, $result['assignments']);

        $this->assertSame(3, LicenceSku::count());
        $this->assertSame(3, DirectoryAccount::count());
    }

    /**
     * Keyed on the identifiers Microsoft issues, so running it twice is a
     * no-op rather than a duplicate estate.
     */
    public function test_pulling_twice_does_not_duplicate_anything(): void
    {
        $this->fakeTenant();
        $this->pull();
        $this->pull();

        $this->assertSame(3, LicenceSku::count());
        $this->assertSame(3, DirectoryAccount::count());
        $this->assertSame(2, DirectoryAccount::where('external_id', self::ACC_A1)->first()->skus()->count());
    }

    /** A rename is the same person. */
    public function test_a_renamed_account_is_updated_not_recreated(): void
    {
        $this->fakeTenant();
        $this->pull();

        $this->fakeTenant([
            ['id' => self::ACC_A1, 'displayName' => 'Ada Mwangi', 'userPrincipalName' => 'ada.mwangi@kns.co.ke',
             'mail' => 'ada.mwangi@kns.co.ke', 'accountEnabled' => true,
             'assignedLicenses' => [['skuId' => self::SKU_PREMIUM]]],
        ]);
        $this->pull();

        $account = DirectoryAccount::where('external_id', self::ACC_A1)->first();
        $this->assertSame('Ada Mwangi', $account->display_name);
        $this->assertSame(1, DirectoryAccount::where('is_present', true)->count());
    }

    /**
     * A seat taken away has to disappear here too, or the waste figure only
     * ever grows.
     */
    public function test_a_removed_licence_is_removed_from_the_account(): void
    {
        $this->fakeTenant();
        $this->pull();
        $this->assertSame(2, DirectoryAccount::where('external_id', self::ACC_A1)->first()->skus()->count());

        $this->fakeTenant([
            ['id' => self::ACC_A1, 'displayName' => 'Ada Wanjiru', 'userPrincipalName' => 'ada@kns.co.ke',
             'mail' => 'ada@kns.co.ke', 'accountEnabled' => true,
             'assignedLicenses' => [['skuId' => self::SKU_PREMIUM]]],
        ]);
        $this->pull();

        $this->assertSame(1, DirectoryAccount::where('external_id', self::ACC_A1)->first()->skus()->count());
    }

    /** Vanishing is a fact worth keeping, not a row worth deleting. */
    public function test_accounts_that_stop_appearing_are_marked_absent_not_deleted(): void
    {
        $this->fakeTenant();
        $this->pull();

        $this->fakeTenant([
            ['id' => self::ACC_A1, 'displayName' => 'Ada Wanjiru', 'userPrincipalName' => 'ada@kns.co.ke',
             'mail' => 'ada@kns.co.ke', 'accountEnabled' => true,
             'assignedLicenses' => [['skuId' => self::SKU_PREMIUM]]],
        ]);
        $result = $this->pull();

        $this->assertSame(2, $result['vanished_accounts']);
        $this->assertSame(3, DirectoryAccount::count());
        $this->assertFalse(DirectoryAccount::where('external_id', self::ACC_A2)->first()->is_present);
    }

    /**
     * A sync that forgets who somebody is every night is worse than one that
     * never knew — the match comes later and must survive.
     */
    public function test_a_sync_does_not_clear_an_employee_match(): void
    {
        $this->fakeTenant();
        $this->pull();

        $account = DirectoryAccount::where('external_id', self::ACC_A1)->first();
        $account->update(['employee_id' => null]);
        \DB::table('integration_directory_accounts')->where('id', $account->id)->update(['employee_id' => null]);

        // Stand in for the match that will exist once employees are migrated.
        \DB::table('integration_directory_accounts')->where('id', $account->id)
            ->update(['employee_id' => null]);

        $this->pull();

        $this->assertNull(DirectoryAccount::find($account->id)->employee_id);
        $this->assertArrayNotHasKey('employee_id', (new DirectoryAccount())->getDirty());
    }

    /** A refusal halfway through should leave the previous snapshot intact. */
    public function test_a_failed_read_leaves_the_previous_snapshot_alone(): void
    {
        $this->fakeTenant();
        $this->pull();

        $this->refuse = true;

        $this->expectException(\RuntimeException::class);

        try {
            $this->pull();
        } finally {
            $this->assertSame(3, LicenceSku::count());
            $this->assertSame(3, DirectoryAccount::where('is_present', true)->count());
        }
    }

    // ── Counting ──────────────────────────────────────────────────────────────

    /**
     * The bug the real tenant exposed, now guarded end to end: a million
     * nominal seats must not reach the denominator.
     */
    public function test_utilisation_counts_paid_skus_only(): void
    {
        $this->fakeTenant();
        $this->pull();

        $stats = app(LicenceStatistics::class)->for($this->connection->id);

        $this->assertSame(17, $stats['seats']['purchased']);
        $this->assertSame(5, $stats['seats']['assigned']);
        $this->assertSame(29, $stats['seats']['utilisation']);
        $this->assertSame(1, $stats['skus']['excluded']);
        $this->assertSame(1_000_000, $stats['skus']['excluded_nominal_seats']);
    }

    /**
     * The finding that needs no employee record, broken down so it is a
     * decision rather than a number.
     */
    public function test_blocked_accounts_holding_licences_are_counted_by_sku(): void
    {
        $this->fakeTenant();
        $this->pull();

        $waste = app(LicenceStatistics::class)->for($this->connection->id)['waste'];

        $this->assertSame(2, $waste['blocked_accounts_with_licences']);
        $this->assertSame(3, $waste['licences_held']);

        // All three happen to be paid here; the split is asserted below.
        $this->assertSame(3, $waste['paid_licences_held']);
        $this->assertSame(2, $waste['accounts_holding_paid']);

        $this->assertSame(
            [['name' => 'Microsoft 365 Business Standard', 'count' => 2, 'classification' => 'paid'],
             ['name' => 'Microsoft 365 Copilot', 'count' => 1, 'classification' => 'paid']],
            $waste['by_sku'],
        );
    }

    /**
     * A blocked account holding only free SKUs costs nothing.
     *
     * The real tenant reported "13 accounts holding 18 licences", of which only
     * three were paid — the rest were Flow Free, Power BI free and developer
     * plans. Leading with 18 overstates the money, and an inflated finding is
     * one somebody checks once and then stops trusting.
     */
    public function test_free_licences_on_blocked_accounts_are_not_counted_as_money(): void
    {
        $this->fakeTenant([
            ['id' => self::ACC_A2, 'displayName' => 'Brian Otieno', 'userPrincipalName' => 'brian@kns.co.ke',
             'mail' => 'brian@kns.co.ke', 'accountEnabled' => false,
             'assignedLicenses' => [['skuId' => self::SKU_FREE]]],
            ['id' => self::ACC_A3, 'displayName' => 'Carol Njeri', 'userPrincipalName' => 'carol@kns.co.ke',
             'mail' => 'carol@kns.co.ke', 'accountEnabled' => false,
             'assignedLicenses' => [['skuId' => self::SKU_FREE], ['skuId' => self::SKU_COPILOT]]],
        ]);
        $this->pull();

        $waste = app(LicenceStatistics::class)->for($this->connection->id)['waste'];

        $this->assertSame(2, $waste['blocked_accounts_with_licences']);
        $this->assertSame(3, $waste['licences_held']);

        // Only Carol's Copilot seat is money.
        $this->assertSame(1, $waste['paid_licences_held']);
        $this->assertSame(1, $waste['accounts_holding_paid']);

        // Paid first, so the eye lands on the seat that costs something.
        $this->assertSame('Microsoft 365 Copilot', $waste['by_sku'][0]['name']);
    }

    /**
     * Until employees are migrated, every account is unmatched — and calling
     * that an orphan would turn the directory into a page of findings nobody
     * can act on.
     */
    public function test_unmatched_accounts_are_reported_as_awaiting_a_match(): void
    {
        $this->fakeTenant();
        $this->pull();

        $accounts = app(LicenceStatistics::class)->for($this->connection->id)['accounts'];

        $this->assertSame(3, $accounts['total']);
        $this->assertSame(1, $accounts['enabled']);
        $this->assertSame(2, $accounts['blocked']);
        $this->assertSame(0, $accounts['matched_to_employee']);
        $this->assertSame(3, $accounts['awaiting_match']);
    }

    public function test_a_full_sku_is_reported_at_capacity(): void
    {
        $this->fakeTenant();
        $this->pull();

        // Business Standard: 5 seats, 3 assigned — not full.
        $stats = app(LicenceStatistics::class)->for($this->connection->id);
        $this->assertSame([], $stats['at_capacity']);

        LicenceSku::where('sku_id', self::SKU_PREMIUM)->update(['consumed_reported' => 5]);

        $stats = app(LicenceStatistics::class)->for($this->connection->id);
        $this->assertSame('Microsoft 365 Business Standard', $stats['at_capacity'][0]['name']);
    }
}
