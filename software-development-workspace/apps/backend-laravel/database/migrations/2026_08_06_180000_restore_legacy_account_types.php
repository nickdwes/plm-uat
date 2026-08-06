<?php

use App\Support\Account\DefaultReferenceData;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Back to the coverage classification Konvergenz actually used.
 *
 * Plmv2 replaced Key / Named / Developing with Prospect / Client / Partner /
 * Supplier, and I seeded that. It was wrong on three counts:
 *
 *   Prospect is a status, not a type. That overlap is the one the previous
 *   change removed from `accounts.status` — reintroducing it in the type list
 *   puts it straight back.
 *
 *   Partner and Supplier are not customers. They belong to OEM and vendor
 *   relationships, which is its own module in the legacy and is not this
 *   table.
 *
 *   Key / Named / Developing was set on all 70 legacy accounts and is
 *   understood by the people using it. A classification with total coverage
 *   and shared meaning is not something to replace for tidiness.
 *
 * The Plmv2 four are removed only where nothing points at them. Deleting a
 * type a customer is filed under would null their classification silently.
 */
return new class extends Migration
{
    private const RETIRED = ['Prospect', 'Client', 'Partner', 'Supplier'];

    public function up(): void
    {
        foreach (DB::table('organizations')->pluck('id') as $orgId) {
            DefaultReferenceData::seedFor((int) $orgId);
        }

        DB::table('account_types')
            ->whereIn('name', self::RETIRED)
            ->whereNotIn('id', fn ($q) => $q->select('account_type_id')
                ->from('accounts')->whereNotNull('account_type_id'))
            ->delete();
    }

    public function down(): void
    {
        $now = now();

        foreach (DB::table('organizations')->pluck('id') as $orgId) {
            foreach (self::RETIRED as $name) {
                DB::table('account_types')->insertOrIgnore([
                    'organization_id' => $orgId, 'name' => $name,
                    'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }
};
