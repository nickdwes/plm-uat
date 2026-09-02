<?php

namespace App\Services\Integration;

use App\Models\Asset\Asset;
use App\Models\Asset\AssetSeatAllocation;
use App\Models\Employee;
use App\Models\Integration\DirectoryAccount;
use App\Models\Integration\IntegrationConnection;
use Illuminate\Support\Facades\DB;

/**
 * Putting a name to every licence seat.
 *
 * The pieces have been in place and unjoined. The nightly sync writes a
 * `directory_accounts` row per licensed person and the SKUs they hold, and it
 * deliberately leaves `employee_id` alone — "the match has not happened yet,
 * and once it has, a sync must not undo it". `LicenceAssetImport` puts the
 * SKUs into the register as products. Neither knows who holds what, so the
 * register reports Microsoft's consumed count beside a seats-used of nought
 * and the difference reads as a fault rather than an unfinished job.
 *
 * Two steps, in order, because the second depends on the first:
 *
 *  1. **Match** a directory account to an employee, by address. Exact only —
 *     a fuzzy name match here allocates somebody else's licence to you.
 *  2. **Allocate** a seat on the licence asset for every SKU that account
 *     holds, and release the seats of people who no longer hold them.
 *
 * Idempotent. Running it twice changes nothing; running it after somebody
 * leaves releases their seats, which is the point — an unreleased seat is a
 * licence being paid for and not used.
 */
class LicenceOwnerMapper
{
    /**
     * Match directory accounts to employees on address.
     *
     * `mail` and `userPrincipalName` are both tried, and both are compared
     * against the employee's work address and their login's. A person whose
     * sign-in name and mailbox differ — a second domain, a migrated account —
     * is the ordinary case here rather than the exception.
     *
     * Also returns what it resolved, keyed by account id. A preview writes
     * nothing, so without this the seat step would look at untouched rows and
     * report that nobody holds anything.
     *
     * @return array{matched:int, already:int, unmatched:int, ambiguous:int, resolved:array<int,int>}
     */
    public function matchAccounts(IntegrationConnection $connection, bool $commit = true): array
    {
        $accounts = DirectoryAccount::query()
            ->where('integration_connection_id', $connection->id)
            ->where('is_present', true)
            ->get();

        /*
         * Addresses that identify exactly one person.
         *
         * An address on two employee records identifies neither — a shared
         * mailbox, or a duplicate somebody has yet to merge — so it is counted
         * as ambiguous and left alone rather than assigned to whichever row
         * the query happened to return first.
         */
        $byAddress = collect();
        $ambiguous = collect();

        $note = function (?string $address, int $employeeId) use ($byAddress, $ambiguous) {
            $key = strtolower(trim((string) $address));

            if ($key === '' || ! str_contains($key, '@')) {
                return;
            }

            if ($byAddress->has($key) && $byAddress[$key] !== $employeeId) {
                $ambiguous->put($key, true);
                return;
            }

            $byAddress->put($key, $employeeId);
        };

        Employee::query()
            ->where('primary_organization_id', $connection->organization_id)
            ->with('user:id,email')
            ->get(['id', 'work_email', 'user_id'])
            ->each(function (Employee $employee) use ($note) {
                $note($employee->work_email, $employee->id);
                $note($employee->user?->email, $employee->id);
            });

        $result   = ['matched' => 0, 'already' => 0, 'unmatched' => 0, 'ambiguous' => 0];
        $resolved = [];

        foreach ($accounts as $account) {
            if ($account->employee_id !== null) {
                $result['already']++;
                $resolved[$account->id] = $account->employee_id;
                continue;
            }

            $employeeId = null;
            $clash      = false;

            foreach ([$account->mail, $account->user_principal_name] as $address) {
                $key = strtolower(trim((string) $address));

                if ($key === '') {
                    continue;
                }

                if ($ambiguous->has($key)) {
                    $clash = true;
                    continue;
                }

                $employeeId ??= $byAddress[$key] ?? null;
            }

            if ($employeeId === null) {
                $result[$clash ? 'ambiguous' : 'unmatched']++;
                continue;
            }

            if ($commit) {
                $account->forceFill(['employee_id' => $employeeId])->save();
            }

            $resolved[$account->id] = $employeeId;
            $result['matched']++;
        }

        return [...$result, 'resolved' => $resolved];
    }

    /**
     * Give every licence asset the seats its holders actually occupy.
     *
     * Works from the matched accounts: a seat we cannot put a name to is not
     * allocated, and the gap between Microsoft's consumed count and the seats
     * here stays visible. Hiding it by allocating anonymous seats would make
     * the register agree with itself and tell nobody anything.
     *
     * @return array{allocated:int, released:int, unmatched_seats:int, assets:int}
     */
    public function allocateSeats(
        IntegrationConnection $connection,
        bool $commit = true,
        array $resolved = [],
    ): array {
        $orgId = $connection->organization_id;

        // The licence products this connection imported, by the SKU they came
        // from — `sku:<guid>` is the reference LicenceAssetImport writes.
        $assetsBySku = Asset::query()
            ->where('organization_id', $orgId)
            ->where('external_source', LicenceAssetImport::SOURCE)
            ->whereNotNull('external_ref')
            ->get()
            ->filter(fn (Asset $a) => str_starts_with((string) $a->external_ref, 'sku:'))
            ->keyBy(fn (Asset $a) => strtolower(substr((string) $a->external_ref, 4)));

        /*
         * On a first run the licence products do not exist yet, so a preview
         * would report nought seats and then the commit would allocate
         * hundreds — the exact surprise a preview exists to prevent. When
         * nothing has been imported, the paid SKUs stand in for the assets
         * the import is about to create.
         */
        $estimating = $assetsBySku->isEmpty() && ! $commit;

        if ($estimating) {
            $assetsBySku = \App\Models\Integration\LicenceSku::query()
                ->where('integration_connection_id', $connection->id)
                ->where('classification', LicenceSkus::PAID)
                ->get()
                ->keyBy(fn ($sku) => strtolower((string) $sku->sku_id));
        }

        if ($assetsBySku->isEmpty()) {
            return ['allocated' => 0, 'released' => 0, 'unmatched_seats' => 0, 'assets' => 0];
        }

        $accounts = DirectoryAccount::query()
            ->where('integration_connection_id', $connection->id)
            ->where('is_present', true)
            ->with('skus:id,sku_id')
            ->get();

        // What the directory says should be allocated: asset id → employee ids.
        $wanted = collect();
        $unmatchedSeats = 0;

        foreach ($accounts as $account) {
            foreach ($account->skus as $sku) {
                $asset = $assetsBySku[strtolower((string) $sku->sku_id)] ?? null;

                if (! $asset) {
                    // A free or viral SKU the import deliberately skipped.
                    continue;
                }

                // The stored match, or the one a preview just worked out.
                $employeeId = $account->employee_id ?? ($resolved[$account->id] ?? null);

                if ($employeeId === null) {
                    $unmatchedSeats++;
                    continue;
                }

                $wanted->put($asset->id . ':' . $employeeId, [
                    'asset_id'    => $asset->id,
                    'employee_id' => $employeeId,
                ]);
            }
        }

        $held = $estimating
            ? collect()
            : AssetSeatAllocation::query()
                ->where('organization_id', $orgId)
                ->whereIn('asset_id', $assetsBySku->pluck('id'))
                ->whereNull('released_on')
                ->get();

        $result = [
            'allocated'       => 0,
            'released'        => 0,
            'unmatched_seats' => $unmatchedSeats,
            'assets'          => $assetsBySku->count(),
            // Says so plainly rather than presenting a projection as a count.
            'estimated'       => $estimating,
        ];

        DB::transaction(function () use ($wanted, $held, $orgId, $commit, &$result) {
            $heldKeys = $held->keyBy(fn (AssetSeatAllocation $a) => $a->asset_id . ':' . $a->employee_id);

            foreach ($wanted as $key => $row) {
                if ($heldKeys->has($key)) {
                    continue;
                }

                if ($commit) {
                    AssetSeatAllocation::create([
                        'organization_id' => $orgId,
                        'asset_id'        => $row['asset_id'],
                        'employee_id'     => $row['employee_id'],
                        'allocated_on'    => now()->toDateString(),
                        'note'            => 'Matched from Microsoft 365',
                    ]);
                }

                $result['allocated']++;
            }

            /*
             * Seats held here that the directory no longer reports.
             *
             * Released rather than deleted: what somebody held, and when, is
             * the question a licence audit asks, and a deleted row cannot
             * answer it.
             */
            foreach ($heldKeys as $key => $allocation) {
                if ($wanted->has($key)) {
                    continue;
                }

                // Only the ones this mapper made. A seat somebody allocated by
                // hand is a decision, and a sync must not quietly undo it.
                if ($allocation->note !== 'Matched from Microsoft 365') {
                    continue;
                }

                if ($commit) {
                    $allocation->forceFill(['released_on' => now()->toDateString()])->save();
                }

                $result['released']++;
            }
        });

        return $result;
    }

    /** Both steps, in the order they depend on each other. */
    public function run(IntegrationConnection $connection, bool $commit = true): array
    {
        $accounts = $this->matchAccounts($connection, $commit);

        return [
            // The resolved map is plumbing, not an answer — the caller wants
            // counts, and shipping a few hundred ids to a browser is noise.
            'accounts' => collect($accounts)->except('resolved')->all(),
            'seats'    => $this->allocateSeats($connection, $commit, $accounts['resolved']),
        ];
    }
}
