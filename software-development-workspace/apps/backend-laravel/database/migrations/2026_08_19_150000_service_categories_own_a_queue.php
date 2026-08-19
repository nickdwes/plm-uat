<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A service category points at the queue that handles it.
 *
 * Routing already falls back from the contract to "the practice that owns this
 * category", but that needs an OrgPractice to exist and a support team to be
 * linked to it. Most organisations set their practices up long after they start
 * taking tickets, so the fallback quietly did nothing and everything landed on
 * the service desk.
 *
 * A direct link makes the standard queues work from the first day, and the
 * practice route still applies where somebody has done that work.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_service_categories', function (Blueprint $table) {
            $table->foreignId('default_team_id')->nullable()->after('org_practice_id')
                ->constrained('support_teams')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('support_service_categories', function (Blueprint $table) {
            $table->dropForeign(['default_team_id']);
            $table->dropColumn('default_team_id');
        });
    }
};
