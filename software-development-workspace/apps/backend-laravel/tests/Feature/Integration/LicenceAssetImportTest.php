<?php

namespace Tests\Feature\Integration;

use App\Models\Asset\Asset;
use App\Models\Asset\AssetCategory;
use App\Models\Integration\IntegrationConnection;
use App\Models\Organization;
use App\Services\Integration\LicenceAssetImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Licences as things in the register, not only as counts.
 *
 * The fixture keeps the shape that made this worth doing: one SKU bought twice,
 * on separate terms, which `subscribedSkus` reports only as an aggregate.
 */
class LicenceAssetImportTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private IntegrationConnection $connection;

    private const SKU_VISIO   = '11111111-1111-1111-1111-111111111111';
    private const SKU_COPILOT = '22222222-2222-2222-2222-222222222222';
    private const SKU_FREE    = '33333333-3333-3333-3333-333333333333';

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);

        AssetCategory::create(['organization_id' => $this->org->id, 'asset_class' => Asset::CLASS_SOFTWARE,
            'name' => 'licence', 'custody_model' => AssetCategory::CUSTODY_NONE]);
        AssetCategory::create(['organization_id' => $this->org->id, 'asset_class' => Asset::CLASS_SUBSCRIPTION,
            'name' => 'saas', 'custody_model' => AssetCategory::CUSTODY_NONE]);

        $this->connection = IntegrationConnection::create([
            'organization_id' => $this->org->id,
            'name'            => 'Alpha — Microsoft 365',
            'tenant_id'       => 'fe3cf4ea-a68e-4f54-b968-50e80b84f4a7',
            'client_id'       => '9b88a83b-5918-431d-bd41-edbc9ba0e9f4',
            'client_secret'   => 'secret',
        ]);

        $this->skus = $this->defaultSkus();
        $this->purchases = $this->defaultPurchases();

        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, 'login.microsoftonline.com')) {
                return Http::response(['access_token' => 'header.e30.sig']);
            }
            if (str_contains($url, 'subscribedSkus')) {
                return Http::response(['value' => $this->skus]);
            }
            if (str_contains($url, 'directory/subscriptions')) {
                return Http::response(['value' => $this->purchases]);
            }

            return Http::response(['value' => []]);
        });
    }

    private array $skus = [];
    private array $purchases = [];

    private function defaultSkus(): array
    {
        return [
            ['skuId' => self::SKU_VISIO, 'skuPartNumber' => 'VISIOCLIENT',
             'consumedUnits' => 2, 'prepaidUnits' => ['enabled' => 10], 'capabilityStatus' => 'Enabled'],
            ['skuId' => self::SKU_COPILOT, 'skuPartNumber' => 'Microsoft_365_Copilot',
             'consumedUnits' => 11, 'prepaidUnits' => ['enabled' => 12], 'capabilityStatus' => 'Enabled'],
            ['skuId' => self::SKU_FREE, 'skuPartNumber' => 'WINDOWS_STORE',
             'consumedUnits' => 0, 'prepaidUnits' => ['enabled' => 1000000], 'capabilityStatus' => 'Enabled'],
        ];
    }

    private function defaultPurchases(): array
    {
        return [
            ['id' => 'p1', 'skuId' => self::SKU_VISIO, 'skuPartNumber' => 'VISIOCLIENT',
             'totalLicenses' => 5, 'status' => 'Enabled', 'nextLifecycleDateTime' => '2026-10-22T00:00:00Z'],
            ['id' => 'p2', 'skuId' => self::SKU_VISIO, 'skuPartNumber' => 'VISIOCLIENT',
             'totalLicenses' => 5, 'status' => 'Enabled', 'nextLifecycleDateTime' => '2026-11-12T00:00:00Z'],
            ['id' => 'p3', 'skuId' => self::SKU_COPILOT, 'skuPartNumber' => 'Microsoft_365_Copilot',
             'totalLicenses' => 12, 'status' => 'Enabled', 'nextLifecycleDateTime' => '2027-04-17T00:00:00Z'],
            // Free SKU: no product, so no agreement either.
            ['id' => 'p4', 'skuId' => self::SKU_FREE, 'skuPartNumber' => 'WINDOWS_STORE',
             'totalLicenses' => 1000000, 'status' => 'Enabled'],
        ];
    }

    private function import(): array
    {
        return app(LicenceAssetImport::class)->import($this->connection);
    }

    // ── The shape ─────────────────────────────────────────────────────────────

    /**
     * The reason this is not one row per SKU: ten Visio seats are two purchases
     * of five renewing three weeks apart, and only the purchases can answer
     * "what renews next month".
     */
    public function test_a_sku_bought_twice_becomes_two_agreements_under_one_product(): void
    {
        $this->import();

        $product = Asset::where('asset_class', Asset::CLASS_SOFTWARE)
            ->where('name', 'Visio Plan 2')->first();

        $this->assertNotNull($product);

        $agreements = Asset::where('parent_asset_id', $product->id)->get();
        $this->assertCount(2, $agreements);
        $this->assertEqualsCanonicalizing([5, 5], $agreements->pluck('seats_total')->all());
        $this->assertEqualsCanonicalizing(
            ['2026-10-22', '2026-11-12'],
            $agreements->pluck('expires_on')->map(fn ($d) => $d->toDateString())->all(),
        );
    }

    /** Two identical names would be two rows nobody can tell apart. */
    public function test_agreements_are_named_by_their_term(): void
    {
        $this->import();

        $names = Asset::where('asset_class', Asset::CLASS_SUBSCRIPTION)->pluck('name')->all();

        $this->assertContains('Visio Plan 2 — to 2026-10-22', $names);
        $this->assertContains('Visio Plan 2 — to 2026-11-12', $names);
    }

    /**
     * Three million nominal seats in the register would make every total
     * meaningless — the same arithmetic that rendered utilisation as nought.
     */
    public function test_free_skus_are_not_imported(): void
    {
        $result = $this->import();

        $this->assertSame(1, $result['excluded_free']);
        $this->assertNull(Asset::where('name', 'Windows Store for Business')->first());
        $this->assertSame(22, $result['seats_total']);
    }

    /**
     * Microsoft's count lands in its own column and never becomes `seats_used`,
     * which is derived from allocations we can name a person for. Until
     * employees are migrated the two are meant to disagree.
     */
    public function test_vendor_reported_seats_are_kept_apart_from_allocations(): void
    {
        $this->import();

        $copilot = Asset::where('name', 'Microsoft 365 Copilot')
            ->where('asset_class', Asset::CLASS_SOFTWARE)->first();

        $this->assertSame(11, $copilot->seats_assigned_reported);
        $this->assertSame(0, $copilot->seatsUsed());
        $this->assertSame(11, $copilot->seatsReportedUnaccounted());
    }

    /**
     * Suspended is not "under repair".
     *
     * Mapping it to maintenance put three licences on the dashboard under a
     * heading that sends somebody looking for a fault. The vendor stopped it;
     * that is an account question, not a workshop one.
     */
    public function test_a_suspended_purchase_is_flagged_rather_than_hidden(): void
    {
        $this->purchases[2]['status'] = 'Suspended';
        $this->import();

        $agreement = Asset::where('name', 'like', 'Microsoft 365 Copilot%')
            ->where('asset_class', Asset::CLASS_SUBSCRIPTION)->first();

        $this->assertSame(Asset::STATUS_SUSPENDED, $agreement->status);
        $this->assertSame('Suspended', $agreement->custom_fields['microsoft_status']);
    }

    // ── Running it twice ──────────────────────────────────────────────────────

    public function test_a_second_import_updates_rather_than_duplicates(): void
    {
        $this->import();
        $result = $this->import();

        $this->assertSame(5, Asset::count());
        $this->assertSame(0, $result['products_new']);
        $this->assertSame(0, $result['agreements_new']);
    }

    /** Seat counts move between renewals, and the register has to follow. */
    public function test_a_changed_seat_count_is_picked_up(): void
    {
        $this->import();

        $this->purchases[2]['totalLicenses'] = 20;
        $this->import();

        $copilot = Asset::where('asset_class', Asset::CLASS_SUBSCRIPTION)
            ->where('name', 'like', 'Microsoft 365 Copilot%')->first();

        $this->assertSame(20, $copilot->seats_total);
    }

    public function test_a_cancelled_purchase_is_retired_not_deleted(): void
    {
        $this->import();

        array_pop($this->purchases);       // drop the free one, no effect
        array_shift($this->purchases);     // cancel a Visio purchase
        $result = $this->import();

        $this->assertSame(1, $result['retired']);
        $this->assertSame(5, Asset::count());
    }

    public function test_an_import_leaves_what_a_person_wrote_alone(): void
    {
        $this->import();

        $product = Asset::where('name', 'Visio Plan 2')->first();
        $product->update(['notes' => 'Design team only. Ask before adding seats.']);

        $this->import();

        $this->assertSame('Design team only. Ask before adding seats.', $product->fresh()->notes);
    }

    public function test_a_preview_writes_nothing(): void
    {
        $preview = app(LicenceAssetImport::class)->preview($this->connection);

        $this->assertFalse($preview['committed']);
        $this->assertSame(2, $preview['products_new']);
        $this->assertSame(3, $preview['agreements_new']);
        $this->assertSame(0, Asset::count());
    }
}
