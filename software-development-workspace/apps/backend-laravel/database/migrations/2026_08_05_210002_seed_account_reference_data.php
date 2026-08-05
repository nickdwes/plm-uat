<?php

use App\Support\Account\DefaultReferenceData;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Types and sectors, ported from Plmv2's seed migrations.
 *
 * These tables shipped empty, which meant every customer had a blank type and
 * a blank sector — and the reason those are tables rather than free text is to
 * be grouped on, which empty columns cannot be.
 *
 * This back-fills organisations that already exist. Organisations created from
 * here on are seeded when they are provisioned; both read the same list from
 * DefaultReferenceData so they cannot drift apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('organizations')->pluck('id') as $orgId) {
            DefaultReferenceData::seedFor((int) $orgId);
        }
    }

    public function down(): void
    {
        // Only rows nothing points at. Deleting a sector that customers are
        // filed under would null their sector silently and lose the
        // classification for good.
        DB::table('account_types')->whereIn('name', DefaultReferenceData::TYPES)
            ->whereNotIn('id', fn ($q) => $q->select('account_type_id')->from('accounts')->whereNotNull('account_type_id'))
            ->delete();

        DB::table('account_sectors')->whereIn('name', DefaultReferenceData::SECTORS)
            ->whereNotIn('id', fn ($q) => $q->select('account_sector_id')->from('accounts')->whereNotNull('account_sector_id'))
            ->delete();
    }
};
