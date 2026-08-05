<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Different journeys, different approvers.
 *
 * A delivery is not approved by the requester's line manager. Moving equipment
 * is a finance and operations decision — finance always, and the COO once the
 * consignment is large enough — and routing it through a department head asks
 * someone to sign for a decision that was never theirs.
 *
 * So the chain is scoped by what kind of journey it is. A stage row with a null
 * `travel_type` is the default chain, used when nothing more specific is
 * configured; a row naming a type replaces it for that type. Null means
 * "applies to all", as it does on the allowance rate card, rather than "unset".
 *
 * Thresholds are unchanged in purpose: they decide which stages a request also
 * passes through, never whether it is approved at all. Every request goes
 * through the chain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('travel_requests', function (Blueprint $table) {
            // local | international | delivery. Decides which chain applies,
            // so it lives on the request rather than being derived from the
            // legs — a trip with a flight and a taxi is still one journey to
            // approve, and legs can change after submission.
            $table->string('travel_type')->default('local')->after('travel_mode');
        });

        Schema::table('travel_approval_stages', function (Blueprint $table) {
            $table->string('travel_type')->nullable()->after('stage');
        });

        // The old unique was (organization, stage), which cannot hold both a
        // default finance stage and a delivery-specific one.
        Schema::table('travel_approval_stages', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'stage']);
        });

        // Postgres treats nulls as distinct in a unique index, so the default
        // row and a typed row coexist without a partial index.
        Schema::table('travel_approval_stages', function (Blueprint $table) {
            $table->unique(['organization_id', 'stage', 'travel_type'], 'tas_org_stage_type_unique');
        });
    }

    public function down(): void
    {
        Schema::table('travel_approval_stages', function (Blueprint $table) {
            $table->dropUnique('tas_org_stage_type_unique');
            $table->dropColumn('travel_type');
            $table->unique(['organization_id', 'stage']);
        });

        Schema::table('travel_requests', function (Blueprint $table) {
            $table->dropColumn('travel_type');
        });
    }
};
