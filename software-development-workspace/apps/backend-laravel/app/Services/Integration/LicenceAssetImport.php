<?php

namespace App\Services\Integration;

use App\Models\Asset\Asset;
use App\Models\Asset\AssetCategory;
use App\Models\Integration\IntegrationConnection;

/**
 * Putting the licences into the register as things, not just as counts.
 *
 * ── A SKU is not a purchase ──
 *
 * `subscribedSkus` reports ten Visio seats. `directory/subscriptions` reports
 * two purchases of five, renewing three weeks apart. Only the second can answer
 * "what renews next month", which is the question the renewals page exists for.
 *
 * That is exactly the shape the register already has: a software product with
 * several subscription agreements underneath it. So a SKU becomes the product
 * and each purchase becomes an agreement, and the seats and renewal dates hang
 * off the agreements where they belong.
 *
 * ── Paid only ──
 *
 * The free and viral SKUs carry nominal ceilings — a million seats on Windows
 * Store, ten thousand on the developer plans. Bringing those in would put three
 * million seats in the register and make every total meaningless, which is the
 * same arithmetic that rendered utilisation as nought before it was fixed.
 */
class LicenceAssetImport
{
    public const SOURCE = 'microsoft365';

    public function __construct(private readonly MicrosoftInventory $inventory) {}

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
            $connection->sync_licences,
            422,
            'That connection is not set to read Microsoft 365 licences.',
        );

        $reader   = $this->inventory->forConnection($connection);
        $skus     = collect($reader->subscribedSkus())
            ->where('classification', LicenceSkus::PAID)
            ->keyBy('sku_id');

        $purchases = collect($reader->commercialSubscriptions())
            ->filter(fn (array $p) => $p['id'] !== null && $skus->has($p['sku_id']));

        $categories = $this->categories($connection->organization_id);

        $products   = [];
        $agreements = [];
        $seen       = [];

        foreach ($skus as $skuId => $sku) {
            $ref = 'sku:' . $skuId;
            $seen[] = $ref;

            $product = $this->upsert($connection, $ref, $commit, [
                'asset_class'       => Asset::CLASS_SOFTWARE,
                'category'          => 'licence',
                'asset_category_id' => $categories['licence'] ?? null,
                'status'            => Asset::STATUS_IN_SERVICE,
            ], [
                'name'   => $sku['name'],
                'vendor' => 'Microsoft',
                /*
                 * Microsoft's own count, in its own column. Not `seats_used` —
                 * that is derived from allocations we can name a person for,
                 * and until employees are migrated the two are meant to
                 * disagree. The gap is the finding.
                 */
                'seats_assigned_reported' => $sku['consumed_reported'],
                'custom_fields' => ['part_number' => $sku['part_number']],
            ]);

            $products[] = ['name' => $sku['name'], 'new' => $product['created']];

            /*
             * Every purchase of this SKU. Grouped so a SKU bought twice gets two
             * agreements with their own terms rather than one row that averages
             * two renewal dates into nothing.
             */
            foreach ($purchases->where('sku_id', $skuId) as $purchase) {
                $agreementRef = 'sub:' . $purchase['id'];
                $seen[] = $agreementRef;

                $row = $this->upsert($connection, $agreementRef, $commit, [
                    'asset_class'       => Asset::CLASS_SUBSCRIPTION,
                    'category'          => 'saas',
                    'asset_category_id' => $categories['saas'] ?? null,
                ], [
                    // The renewal date is in the name because a SKU bought twice
                    // otherwise shows as two identical rows, and the term is the
                    // one thing that tells them apart.
                    'name' => $purchase['renews_on']
                        ? $sku['name'] . ' — to ' . $purchase['renews_on']
                        : $sku['name'],
                    'vendor'          => 'Microsoft',
                    'seats_total'     => $purchase['total'],
                    'expires_on'      => $purchase['renews_on'],
                    'parent_asset_id' => $product['id'],
                    /*
                     * Suspended, not "under repair".
                     *
                     * The vendor stopped it — a lapsed term or a payment — and
                     * filing that as maintenance put three licences on the
                     * dashboard under a heading that sends somebody hunting a
                     * fault that does not exist. It is an account question.
                     */
                    'status'          => $purchase['status'] === 'Enabled'
                        ? Asset::STATUS_IN_SERVICE
                        : Asset::STATUS_SUSPENDED,
                    'custom_fields'   => array_filter([
                        'part_number'      => $purchase['part_number'],
                        'microsoft_status' => $purchase['status'],
                    ]),
                ]);

                $agreements[] = [
                    'name'     => $sku['name'],
                    'seats'    => $purchase['total'],
                    'renews'   => $purchase['renews_on'],
                    'status'   => $purchase['status'],
                    'new'      => $row['created'],
                ];
            }
        }

        $retired = $this->missing($connection, $seen, $commit);

        return [
            'committed'      => $commit,
            'products'       => $products,
            'agreements'     => $agreements,
            'products_new'   => count(array_filter($products, fn ($p) => $p['new'])),
            'agreements_new' => count(array_filter($agreements, fn ($a) => $a['new'])),
            'seats_total'    => array_sum(array_column($agreements, 'seats')),
            'retired'        => $retired,
            // Named so the reader can see what was left out and why.
            'excluded_free'  => count($reader->subscribedSkus()) - $skus->count(),
        ];
    }

    /**
     * @return array{id: int|null, created: bool}
     */
    private function upsert(
        IntegrationConnection $connection,
        string $ref,
        bool $commit,
        array $onCreate,
        array $onRefresh,
    ): array {
        $existing = Asset::withoutGlobalScope('organization')
            ->where('organization_id', $connection->organization_id)
            ->where('external_source', self::SOURCE)
            ->where('external_ref', $ref)
            ->first();

        if ($existing) {
            if ($commit) {
                // Only the vendor's facts. Notes, cost and anything a person set
                // are left alone — an import that overwrites corrections every
                // night trains people to stop making them.
                $existing->forceFill($onRefresh + ['external_synced_at' => now()])->save();
            }

            return ['id' => $existing->id, 'created' => false];
        }

        if (! $commit) {
            return ['id' => null, 'created' => true];
        }

        $asset = Asset::withoutGlobalScope('organization')->create(
            $onRefresh + $onCreate + [
                'organization_id'    => $connection->organization_id,
                'external_source'    => self::SOURCE,
                'external_ref'       => $ref,
                'external_synced_at' => now(),
                // Required and unique, and Microsoft issues nothing like it.
                // Derived from the ref so it is identical on every re-run.
                'asset_tag'          => 'M365-' . strtoupper(substr(sha1($ref), 0, 8)),
            ],
        );

        return ['id' => $asset->id, 'created' => true];
    }

    /** Licences no longer reported: retired, never deleted. */
    private function missing(IntegrationConnection $connection, array $seen, bool $commit): int
    {
        $refs = array_map(fn ($r) => $r, $seen);

        $query = Asset::withoutGlobalScope('organization')
            ->where('organization_id', $connection->organization_id)
            ->where('external_source', self::SOURCE)
            ->where('status', '!=', Asset::STATUS_RETIRED)
            ->when($refs !== [], fn ($q) => $q->whereNotIn('external_ref', $refs));

        return $commit
            ? $query->update(['status' => Asset::STATUS_RETIRED])
            : $query->count();
    }

    /** @return array<string, int> */
    private function categories(int $organizationId): array
    {
        return AssetCategory::withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->whereIn('asset_class', [Asset::CLASS_SOFTWARE, Asset::CLASS_SUBSCRIPTION])
            ->pluck('id', 'name')
            ->all();
    }
}
