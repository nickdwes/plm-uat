<?php

namespace Tests\Feature\Integration;

use App\Models\Asset\Asset;
use App\Models\Asset\AssetCategory;
use App\Models\Integration\IntegrationConnection;
use App\Models\Organization;
use App\Services\Integration\AzureResourceImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Turning a subscription into things the register can talk about.
 *
 * The fixture is the real subscription in miniature: a few machines worth
 * caring about, surrounded by the network interfaces, disks and metric alerts
 * that make up three quarters of what Azure reports.
 */
class AzureResourceImportTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private IntegrationConnection $connection;

    private const SUB = '45507162-7883-416f-9efa-d41d08340e1d';

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);

        foreach (['virtual machine', 'key vault', 'virtual network', 'storage account', 'ai service'] as $name) {
            AssetCategory::create([
                'organization_id' => $this->org->id,
                'asset_class'     => Asset::CLASS_CLOUD,
                'name'            => $name,
                'custody_model'   => AssetCategory::CUSTODY_CUSTODIAN,
            ]);
        }

        $this->connection = IntegrationConnection::create([
            'organization_id' => $this->org->id,
            'name'            => 'Alpha — Azure',
            'tenant_id'       => 'fe3cf4ea-a68e-4f54-b968-50e80b84f4a7',
            'client_id'       => '9b88a83b-5918-431d-bd41-edbc9ba0e9f4',
            'client_secret'   => 'secret',
            'sync_azure'      => true,
        ]);

        $this->connection->subscriptions()->create([
            'subscription_id' => self::SUB,
            'display_name'    => 'Sponsorship',
            'is_enabled'      => true,
        ]);

        $this->resources = $this->defaultResources();

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'login.microsoftonline.com')) {
                return Http::response(['access_token' => 'header.e30.sig']);
            }

            return Http::response(['data' => $this->resources, 'count' => count($this->resources)]);
        });
    }

    private array $resources = [];

    private function resource(string $id, string $name, string $type, array $extra = []): array
    {
        return array_merge([
            'id'             => "/subscriptions/" . self::SUB . "/resourceGroups/kon1/providers/{$type}/{$id}",
            'name'           => $name,
            'type'           => $type,
            'location'       => 'southafricanorth',
            'resourceGroup'  => 'kon1',
            'subscriptionId' => self::SUB,
            'tags'           => [],
        ], $extra);
    }

    private function defaultResources(): array
    {
        return [
            $this->resource('KonPlm', 'KonPlm', 'microsoft.compute/virtualmachines', [
                'sku'  => ['name' => 'Standard_D2s_v3'],
                'tags' => ['Environment' => 'Production', 'Owner' => 'Grace Njoroge'],
            ]),
            $this->resource('plm-test1', 'plm-test1', 'microsoft.compute/virtualmachines'),
            $this->resource('hrms', 'hrms', 'microsoft.keyvault/vaults'),
            $this->resource('KonPlm-vnet', 'KonPlm-vnet', 'microsoft.network/virtualnetworks'),

            // Plumbing. Real, and not separate assets.
            $this->resource('nic-1', 'KonPlm-nic', 'microsoft.network/networkinterfaces'),
            $this->resource('ip-1', 'KonPlm-ip', 'microsoft.network/publicipaddresses'),
            $this->resource('disk-1', 'KonPlm-disk', 'microsoft.compute/disks'),
            $this->resource('alert-1', 'cpu-high', 'microsoft.insights/metricalerts'),
        ];
    }

    private function import(): array
    {
        return app(AzureResourceImport::class)->import($this->connection);
    }

    // ── What becomes an asset ─────────────────────────────────────────────────

    /**
     * A register where four machines are buried under seven metric alerts is
     * one nobody scans, and one nobody scans is one nobody reads.
     */
    public function test_plumbing_is_not_imported_as_assets(): void
    {
        $result = $this->import();

        $this->assertCount(4, $result['to_create']);
        $this->assertSame(4, $result['skipped_total']);
        $this->assertSame(4, Asset::where('asset_class', Asset::CLASS_CLOUD)->count());

        $this->assertSame([], Asset::where('category', 'network interface')->get()->all());
    }

    /** What was left out is named, so it can be asked for back. */
    public function test_skipped_types_are_reported_by_name(): void
    {
        $result = $this->import();

        $this->assertArrayHasKey('microsoft.compute/disks', $result['skipped_types']);
        $this->assertArrayHasKey('microsoft.insights/metricalerts', $result['skipped_types']);
    }

    public function test_resources_are_filed_under_the_right_category(): void
    {
        $this->import();

        $this->assertSame('virtual machine', Asset::where('name', 'KonPlm')->first()->category);
        $this->assertSame('key vault', Asset::where('name', 'hrms')->first()->category);
        $this->assertSame('virtual network', Asset::where('name', 'KonPlm-vnet')->first()->category);
    }

    /** Cloud resources are looked after, never handed out. */
    public function test_imported_resources_are_in_service_and_use_a_custodian(): void
    {
        $this->import();

        $vm = Asset::where('name', 'KonPlm')->first();

        $this->assertSame(Asset::STATUS_IN_SERVICE, $vm->status);
        $this->assertTrue($vm->usesCustodian());
        $this->assertNull($vm->assigned_to);
    }

    public function test_azure_facts_land_in_the_configured_fields(): void
    {
        $this->import();

        $vm = Asset::where('name', 'KonPlm')->first();

        $this->assertSame('kon1', $vm->custom_fields['resource_group']);
        $this->assertSame('Standard_D2s_v3', $vm->custom_fields['sku']);
        $this->assertSame('Production', $vm->custom_fields['environment']);
        $this->assertSame('Grace Njoroge', $vm->custom_fields['owner']);
        $this->assertSame('southafricanorth', $vm->location);
    }

    /** Most of this tenant carries no tags, and the import says so. */
    public function test_untagged_resources_are_counted(): void
    {
        $this->assertSame(3, $this->import()['untagged']);
    }

    // ── Running it twice ──────────────────────────────────────────────────────

    /** Keyed on the ARM resource id, which survives a rename. */
    public function test_a_second_import_updates_rather_than_duplicates(): void
    {
        $this->import();
        $result = $this->import();

        $this->assertSame(4, Asset::count());
        $this->assertCount(0, $result['to_create']);
        $this->assertSame(4, $result['to_update']);
    }

    public function test_a_renamed_resource_keeps_its_row(): void
    {
        $this->import();
        $id = Asset::where('name', 'KonPlm')->first()->id;

        $this->resources[0]['name'] = 'KonPlm-renamed';
        $this->import();

        $this->assertSame('KonPlm-renamed', Asset::find($id)->name);
        $this->assertSame(4, Asset::count());
    }

    /**
     * An import that overwrites human corrections every night trains people to
     * stop making them.
     */
    public function test_an_import_does_not_undo_what_a_person_set(): void
    {
        $this->import();

        $vm = Asset::where('name', 'KonPlm')->first();
        $vm->update(['notes' => 'Runs the old PLM. Do not reboot in business hours.']);

        $this->import();

        $this->assertSame(
            'Runs the old PLM. Do not reboot in business hours.',
            $vm->fresh()->notes,
        );
    }

    // ── Disappearing ──────────────────────────────────────────────────────────

    /** "What were we running in March" is asked after an incident. */
    public function test_a_deleted_resource_is_retired_not_removed(): void
    {
        $this->import();

        array_shift($this->resources);
        $result = $this->import();

        $this->assertSame(1, $result['retired']);
        $this->assertSame(4, Asset::count());
        $this->assertSame(Asset::STATUS_RETIRED, Asset::where('name', 'KonPlm')->first()->status);
    }

    /**
     * The sync only ever sees rows it created. A laptop somebody registered by
     * hand carries no external ref and must survive an Azure import untouched.
     */
    public function test_hand_registered_assets_are_never_touched(): void
    {
        $laptop = Asset::create([
            'organization_id' => $this->org->id,
            'asset_class'     => Asset::CLASS_PHYSICAL,
            'category'        => 'laptop',
            'name'            => 'ThinkPad X1',
            'asset_tag'       => 'KNS-001',
            'status'          => Asset::STATUS_ASSIGNED,
        ]);

        $this->resources = [];

        try {
            $this->import();
        } catch (\Throwable) {
            // An empty subscription is allowed to fail; the laptop is the point.
        }

        $laptop->refresh();
        $this->assertSame(Asset::STATUS_ASSIGNED, $laptop->status);
        $this->assertNull($laptop->external_ref);
    }

    // ── The preview ───────────────────────────────────────────────────────────

    /** The preview walks the same code and writes nothing. */
    public function test_a_preview_creates_nothing(): void
    {
        $preview = app(AzureResourceImport::class)->preview($this->connection);

        $this->assertFalse($preview['committed']);
        $this->assertCount(4, $preview['to_create']);
        $this->assertSame(0, Asset::count());
    }

    /** Nothing is imported from a subscription nobody ticked. */
    public function test_unticked_subscriptions_are_refused(): void
    {
        $this->connection->subscriptions()->update(['is_enabled' => false]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $this->import();
    }
}
