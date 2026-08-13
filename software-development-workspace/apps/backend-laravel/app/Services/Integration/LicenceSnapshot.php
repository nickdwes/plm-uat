<?php

namespace App\Services\Integration;

use App\Models\Integration\DirectoryAccount;
use App\Models\Integration\IntegrationConnection;
use App\Models\Integration\LicenceSku;
use Illuminate\Support\Facades\DB;

/**
 * Writing down what the tenant says, so it can be counted without asking again.
 *
 * Idempotent by construction: everything is keyed on the identifiers Microsoft
 * issues — the SKU id and the directory object id — rather than on names, which
 * change. Running this twice produces the same rows; running it after somebody
 * is renamed updates the name rather than creating a second person.
 */
class LicenceSnapshot
{
    public function __construct(private readonly MicrosoftInventory $inventory) {}

    /**
     * Read the tenant and write the snapshot.
     *
     * One transaction, because a half-written snapshot is worse than a stale
     * one: statistics computed over SKUs that were updated and accounts that
     * were not would report seats held by nobody, and look like a finding.
     *
     * @return array{skus: int, accounts: int, assignments: int, vanished_skus: int, vanished_accounts: int}
     */
    public function pull(IntegrationConnection $connection): array
    {
        $reader = $this->inventory->forConnection($connection);

        // Read first, write second. A refusal from Microsoft halfway through
        // should leave the previous snapshot untouched rather than truncated.
        $skus     = $reader->subscribedSkus();
        $accounts = $reader->licensedUsers();

        return DB::transaction(function () use ($connection, $skus, $accounts) {
            $now = now();

            $skuIdByGuid = $this->writeSkus($connection, $skus, $now);
            [$assignments, $accountIds] = $this->writeAccounts($connection, $accounts, $skuIdByGuid, $now);

            /*
             * Anything the tenant no longer reports is marked absent, never
             * deleted. A licence that disappears usually means somebody removed
             * a seat, which is precisely what a cost review wants to see — and
             * "what did we have in March" is asked after an invoice is queried.
             *
             * Marked by identity rather than by comparing `last_seen_at` to the
             * start of this run. Laravel's `timestamp()` is second-resolution on
             * Postgres, so two syncs inside the same second carry identical
             * stamps and a `<` comparison marks nothing — which is exactly how
             * an absent account would keep being counted as present.
             */
            $vanishedSkus = LicenceSku::withoutGlobalScope('organization')
                ->where('integration_connection_id', $connection->id)
                ->where('is_present', true)
                ->when($skuIdByGuid !== [], fn ($q) => $q->whereNotIn('id', array_values($skuIdByGuid)))
                ->update(['is_present' => false]);

            $vanishedAccounts = DirectoryAccount::withoutGlobalScope('organization')
                ->where('integration_connection_id', $connection->id)
                ->where('is_present', true)
                ->when($accountIds !== [], fn ($q) => $q->whereNotIn('id', $accountIds))
                ->update(['is_present' => false]);

            $connection->update(['last_synced_at' => $now]);

            return [
                'skus'              => count($skus),
                'accounts'          => count($accounts),
                'assignments'       => $assignments,
                'vanished_skus'     => $vanishedSkus,
                'vanished_accounts' => $vanishedAccounts,
            ];
        });
    }

    /** @return array<string, int> Graph sku id → local row id */
    private function writeSkus(IntegrationConnection $connection, array $skus, $now): array
    {
        $map = [];

        foreach ($skus as $sku) {
            if (blank($sku['sku_id'])) {
                continue;
            }

            $row = LicenceSku::withoutGlobalScope('organization')->updateOrCreate(
                [
                    'integration_connection_id' => $connection->id,
                    'sku_id'                    => $sku['sku_id'],
                ],
                [
                    'organization_id'   => $connection->organization_id,
                    'part_number'       => $sku['part_number'],
                    'name'              => $sku['name'],
                    'classification'    => $sku['classification'],
                    'seats_total'       => $sku['seats_total'],
                    'consumed_reported' => $sku['consumed_reported'],
                    'suspended_units'   => $sku['suspended'],
                    'capability_status' => $sku['capability_status'],
                    'last_seen_at'      => $now,
                    'is_present'        => true,
                ],
            );

            $map[$sku['sku_id']] = $row->id;
        }

        return $map;
    }

    /** @return array{0: int, 1: array<int>} assignment count, and the rows seen */
    private function writeAccounts(
        IntegrationConnection $connection,
        array $accounts,
        array $skuIdByGuid,
        $now,
    ): array {
        $assignments = 0;
        $seen        = [];

        foreach ($accounts as $account) {
            if (blank($account['id'])) {
                continue;
            }

            $row = DirectoryAccount::withoutGlobalScope('organization')->updateOrCreate(
                [
                    'integration_connection_id' => $connection->id,
                    'external_id'               => $account['id'],
                ],
                [
                    'organization_id'     => $connection->organization_id,
                    'display_name'        => $account['name'],
                    'user_principal_name' => $account['upn'],
                    'mail'                => $account['mail'],
                    'account_enabled'     => $account['enabled'],
                    'licence_count'       => count($account['sku_ids']),
                    'last_seen_at'        => $now,
                    'is_present'          => true,
                    /*
                     * `employee_id` is deliberately absent from this write.
                     * The match has not happened yet, and once it has, a sync
                     * must not undo it — an import that forgets who somebody is
                     * every night is worse than one that never knew.
                     */
                ],
            );

            $ids = collect($account['sku_ids'])
                ->map(fn ($guid) => $skuIdByGuid[$guid] ?? null)
                ->filter()
                ->unique()
                ->values()
                ->all();

            // sync() rather than attach(): a seat taken away between runs has
            // to disappear here too, or the waste figure only ever grows.
            $row->skus()->sync($ids);

            $assignments += count($ids);
            $seen[]       = $row->id;
        }

        return [$assignments, $seen];
    }
}
