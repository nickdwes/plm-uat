<?php

namespace App\Support\Account;

use Illuminate\Support\Facades\DB;

/**
 * Human references for customers and contracts (CL-10234, CT-00871).
 *
 * Allocated from the highest existing number rather than from a row count, so
 * a deleted record never causes a reference to be handed out twice. A quoted
 * reference has to keep resolving to the same record years later — that is the
 * whole reason it exists rather than the id.
 *
 * Allocation is serialised on a transaction-scoped advisory lock, because two
 * people creating a customer at once is not rare and the failure it causes —
 * two customers sharing a reference — is invisible until somebody quotes one.
 * A row lock cannot do this job: Postgres refuses FOR UPDATE alongside an
 * aggregate, and there is no row to lock for a reference that does not exist
 * yet. The lock is keyed on the series, so allocating a contract reference
 * never waits behind a customer one.
 *
 * Import matters here: a migration bringing the legacy book across should pass
 * the references it already has straight through, and only fall back to
 * allocation for records that never had one. Because the next number is read
 * from the highest in the table, an import of CL-00001..CL-00070 leaves this
 * continuing at CL-10000 rather than colliding.
 */
final class ReferenceSequence
{
    /** Where each series starts, so early references do not look like test data. */
    private const FLOOR = 10000;

    public static function next(string $prefix, string $table, int $organizationId): string
    {
        return DB::transaction(function () use ($prefix, $table, $organizationId) {
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ["{$table}:{$prefix}:{$organizationId}"]);

            $highest = DB::table($table)
                ->where('organization_id', $organizationId)
                ->where('reference', 'like', $prefix . '-%')
                // Ordering the text would put "CL-9" after "CL-10000"; the
                // numeric tail is what is being sequenced. Anything that does
                // not end in digits is skipped rather than throwing, so one
                // hand-typed reference cannot stop the series.
                ->selectRaw(
                    "MAX(CASE WHEN reference ~ ? THEN substring(reference from '[0-9]+$')::bigint END) AS n",
                    ['^' . preg_quote($prefix, '/') . '-[0-9]+$'],
                )
                ->value('n');

            return $prefix . '-' . max((int) $highest + 1, self::FLOOR);
        });
    }
}
