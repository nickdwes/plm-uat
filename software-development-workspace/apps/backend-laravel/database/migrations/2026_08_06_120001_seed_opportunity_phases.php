<?php

use App\Support\Account\DefaultReferenceData;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The sales process, for organisations that already exist.
 *
 * Same arrangement as the customer types and sectors: this back-fills, and
 * provisioning seeds anything created from here on, both reading one list so
 * they cannot drift. Without phases an opportunity has nowhere to sit and the
 * pipeline cannot say whether anything is won.
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
        // Only phases nothing sits in. Deleting one an opportunity points at
        // would null its phase, and the phase is what says whether the deal
        // was won.
        DB::table('opportunity_phases')
            ->whereNotIn('id', fn ($q) => $q->select('opportunity_phase_id')
                ->from('opportunities')->whereNotNull('opportunity_phase_id'))
            ->delete();
    }
};
