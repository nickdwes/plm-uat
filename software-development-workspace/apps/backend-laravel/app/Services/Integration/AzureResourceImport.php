<?php

namespace App\Services\Integration;

use App\Models\Asset\Asset;
use App\Models\Asset\AssetCategory;
use App\Models\Integration\IntegrationConnection;
use Illuminate\Support\Facades\DB;

/**
 * Turning an Azure subscription into things the register can talk about.
 *
 * ── Not everything is an asset ──
 *
 * The tenant reports forty-nine resources. Thirty-six of them are network
 * interfaces, public IPs, security groups, disks, SSH keys, network watchers
 * and metric alerts — plumbing that belongs to something else and has no
 * separate existence. Importing all of it produces a register where the four
 * machines somebody actually cares about are buried under seven metric alerts,
 * and a register nobody can scan is a register nobody reads.
 *
 * So the map below is a whitelist, and everything outside it is counted and
 * reported rather than silently dropped — a reader who does not know what was
 * skipped cannot ask for it back.
 *
 * ── Never destructive ──
 *
 * A resource that disappears from Azure is retired here, not deleted. "What
 * were we running in March" is asked after an incident, and a register that
 * only holds the present cannot answer it.
 *
 * And nothing typed in by hand is ever touched: the sync only sees rows
 * carrying the external ref it wrote itself.
 */
class AzureResourceImport
{
    public const SOURCE = 'azure';

    /**
     * ARM type → the cloud category it is filed under.
     *
     * Grouped rather than one-to-one: a firewall, a bastion and a load balancer
     * are all things somebody looks after under "virtual network", and eleven
     * categories that each hold one row is not a taxonomy.
     */
    private const TYPE_MAP = [
        'microsoft.compute/virtualmachines'             => 'virtual machine',
        'microsoft.classiccompute/virtualmachines'      => 'virtual machine',

        'microsoft.sql/servers'                         => 'managed database',
        'microsoft.sql/managedinstances'                => 'managed database',
        'microsoft.dbforpostgresql/flexibleservers'     => 'managed database',
        'microsoft.dbforpostgresql/servers'             => 'managed database',
        'microsoft.dbformysql/flexibleservers'          => 'managed database',
        'microsoft.dbformysql/servers'                  => 'managed database',
        'microsoft.documentdb/databaseaccounts'         => 'managed database',
        'microsoft.cache/redis'                         => 'managed database',

        'microsoft.storage/storageaccounts'             => 'storage account',
        'microsoft.keyvault/vaults'                     => 'key vault',

        'microsoft.web/sites'                           => 'app service',
        'microsoft.web/serverfarms'                     => 'app service',
        'microsoft.web/staticsites'                     => 'app service',

        'microsoft.containerservice/managedclusters'    => 'container service',
        'microsoft.containerregistry/registries'        => 'container service',
        'microsoft.app/containerapps'                   => 'container service',
        'microsoft.containerinstance/containergroups'   => 'container service',

        'microsoft.network/virtualnetworks'             => 'virtual network',
        'microsoft.network/azurefirewalls'              => 'virtual network',
        'microsoft.network/bastionhosts'                => 'virtual network',
        'microsoft.network/applicationgateways'         => 'virtual network',
        'microsoft.network/loadbalancers'               => 'virtual network',
        'microsoft.network/virtualnetworkgateways'      => 'virtual network',

        'microsoft.recoveryservices/vaults'             => 'backup vault',
        'microsoft.dataprotection/backupvaults'         => 'backup vault',

        'microsoft.cognitiveservices/accounts'          => 'ai service',
        'microsoft.machinelearningservices/workspaces'  => 'ai service',
        'microsoft.search/searchservices'               => 'ai service',

        'microsoft.operationalinsights/workspaces'      => 'monitoring',
        'microsoft.insights/components'                 => 'monitoring',
    ];

    public function __construct(private readonly MicrosoftInventory $inventory) {}

    /**
     * What an import would do, without doing it.
     *
     * The preview and the commit walk identical code so the second cannot
     * surprise somebody who read the first — the only difference is whether
     * anything is written.
     */
    public function preview(IntegrationConnection $connection): array
    {
        return $this->run($connection, commit: false);
    }

    public function import(IntegrationConnection $connection): array
    {
        return $this->run($connection, commit: true);
    }

    private function run(IntegrationConnection $connection, bool $commit): array
    {
        abort_unless(
            $connection->sync_azure,
            422,
            'That connection is not set to read Azure resources.',
        );

        $subscriptions = $connection->subscriptions()->where('is_enabled', true)->get();

        abort_if(
            $subscriptions->isEmpty(),
            422,
            'No subscriptions are ticked for this connection. Find them and choose which to import.',
        );

        $reader     = $this->inventory->forConnection($connection);
        $categories = $this->categories($connection->organization_id);

        $create   = [];
        $update   = [];
        $skipped  = [];
        $seenRefs = [];
        $untagged = 0;

        foreach ($subscriptions as $subscription) {
            foreach ($reader->azureResources($subscription->subscription_id) as $resource) {
                $type     = strtolower((string) $resource['type']);
                $category = self::TYPE_MAP[$type] ?? null;

                if ($category === null) {
                    $skipped[$type] = ($skipped[$type] ?? 0) + 1;

                    continue;
                }

                $seenRefs[] = $resource['resource_id'];

                if ($resource['tags'] === []) {
                    $untagged++;
                }

                $existing = Asset::withoutGlobalScope('organization')
                    ->where('organization_id', $connection->organization_id)
                    ->where('external_source', self::SOURCE)
                    ->where('external_ref', $resource['resource_id'])
                    ->first();

                $attributes = $this->attributes($connection, $resource, $category, $categories);

                if ($existing) {
                    $update[] = ['name' => $resource['name'], 'category' => $category];

                    if ($commit) {
                        /*
                         * `name`, `category` and the Azure facts are refreshed;
                         * everything a person has since filled in — custodian,
                         * notes, purchase value — is left alone. An import that
                         * overwrites human corrections every night trains people
                         * to stop making them.
                         */
                        $existing->forceFill($attributes['refresh'])->save();
                    }
                } else {
                    $create[] = ['name' => $resource['name'], 'category' => $category, 'type' => $type];

                    if ($commit) {
                        Asset::withoutGlobalScope('organization')
                            ->create($attributes['create'] + $attributes['refresh']);
                    }
                }
            }
        }

        $gone = $this->missing($connection, $seenRefs, $commit);

        if ($commit) {
            foreach ($subscriptions as $subscription) {
                $subscription->update([
                    'last_synced_at'  => now(),
                    'resource_count'  => count($create) + count($update),
                ]);
            }
        }

        arsort($skipped);

        return [
            'committed'    => $commit,
            'to_create'    => $create,
            'to_update'    => count($update),
            'retired'      => $gone,
            'untagged'     => $untagged,
            // Named and counted, because a reader who does not know what was
            // left out cannot ask for it.
            'skipped_types' => $skipped,
            'skipped_total' => array_sum($skipped),
        ];
    }

    /**
     * The fields an imported resource carries.
     *
     * Split into what only matters at creation and what a re-run refreshes, so
     * the update path cannot accidentally reset a tag somebody set by hand.
     */
    private function attributes(
        IntegrationConnection $connection,
        array $resource,
        string $category,
        array $categories,
    ): array {
        $tags = collect($resource['tags'])->mapWithKeys(
            fn ($v, $k) => [strtolower((string) $k) => $v],
        );

        return [
            'create' => [
                'organization_id' => $connection->organization_id,
                'asset_class'     => Asset::CLASS_CLOUD,
                'external_source' => self::SOURCE,
                'external_ref'    => $resource['resource_id'],
                /*
                 * A tag is required and unique, and Azure has no concept of
                 * one. Derived from the resource id so it is stable across
                 * renames and identical on every re-run.
                 */
                'asset_tag'       => 'AZ-' . strtoupper(substr(sha1((string) $resource['resource_id']), 0, 8)),
                'status'          => Asset::STATUS_IN_SERVICE,
            ],

            'refresh' => [
                'name'              => $resource['name'] ?: '(unnamed)',
                'category'          => $category,
                'asset_category_id' => $categories[$category] ?? null,
                'location'          => $resource['location'],
                'vendor'            => 'Microsoft Azure',
                'external_synced_at' => now(),
                'custom_fields'     => array_filter([
                    'resource_group' => $resource['resource_group'],
                    'subscription'   => $resource['subscription'],
                    'sku'            => $resource['sku'],
                    'environment'    => $tags->get('environment') ?? $tags->get('env'),
                    'owner'          => $tags->get('owner'),
                ], fn ($v) => $v !== null && $v !== ''),
            ],
        ];
    }

    /**
     * Resources the subscription no longer reports.
     *
     * Retired, never deleted, and only ever rows this import created — a
     * hand-registered laptop carries no external ref and is invisible here.
     */
    private function missing(IntegrationConnection $connection, array $seenRefs, bool $commit): int
    {
        $query = Asset::withoutGlobalScope('organization')
            ->where('organization_id', $connection->organization_id)
            ->where('external_source', self::SOURCE)
            ->where('status', '!=', Asset::STATUS_RETIRED)
            ->when($seenRefs !== [], fn ($q) => $q->whereNotIn('external_ref', $seenRefs));

        if (! $commit) {
            return $query->count();
        }

        return $query->update([
            'status'     => Asset::STATUS_RETIRED,
            'notes'      => DB::raw("coalesce(notes || E'\\n', '') || 'No longer present in Azure at " . now()->toDateString() . ".'"),
        ]);
    }

    /** @return array<string, int> category name → id, for this organisation */
    private function categories(int $organizationId): array
    {
        return AssetCategory::withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->where('asset_class', Asset::CLASS_CLOUD)
            ->pluck('id', 'name')
            ->all();
    }
}
