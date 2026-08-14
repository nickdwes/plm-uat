<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which tenant an imported asset came from.
 *
 * `external_source` says "microsoft365" or "azure", which was enough while
 * there was one directory. An organisation may register several — the whole
 * point of the connections screen — and then "which tenant is this Business
 * Premium on" has no answer, and two tenants holding the same SKU produce two
 * rows that look like duplicates of each other.
 *
 * Null means somebody typed it in. That stays true: a hand-registered laptop
 * belongs to no tenant and must never be swept up by a sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->foreignId('integration_connection_id')->nullable()->after('external_source')
                ->constrained('integration_connections')->nullOnDelete();
        });

        /*
         * Backfill, but only where it cannot be guessed wrong.
         *
         * An organisation with exactly one connection has only one candidate,
         * so imported rows can be attributed with certainty. Where there are
         * several the answer is genuinely unknown and is left null rather than
         * invented — the next sync writes it correctly, and a wrong tenant on a
         * licence is worse than a blank one.
         */
        DB::statement("
            UPDATE assets a
               SET integration_connection_id = c.id
              FROM integration_connections c
             WHERE a.external_source IS NOT NULL
               AND a.integration_connection_id IS NULL
               AND c.organization_id = a.organization_id
               AND c.deleted_at IS NULL
               AND (
                   SELECT count(*) FROM integration_connections c2
                    WHERE c2.organization_id = a.organization_id
                      AND c2.deleted_at IS NULL
               ) = 1
        ");
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('integration_connection_id');
        });
    }
};
