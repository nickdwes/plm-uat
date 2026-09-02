<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A task's travel carries a location and a mode.
 *
 * Travel is flagged at the task level; to consolidate tasks into cost-effective
 * travel cycles the schedule needs to know WHERE each one goes and HOW. The
 * site (and dates and team, which the task already has) are what let related
 * trips be grouped into one journey.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_logistics', function (Blueprint $table) {
            if (! Schema::hasColumn('task_logistics', 'travel_location')) {
                $table->string('travel_location', 160)->nullable()->after('needs_travel');
            }
            if (! Schema::hasColumn('task_logistics', 'travel_mode')) {
                $table->string('travel_mode', 20)->nullable()->after('travel_location'); // air / road / inter_city / local
            }
        });
    }

    public function down(): void
    {
        Schema::table('task_logistics', function (Blueprint $table) {
            foreach (['travel_location', 'travel_mode'] as $c) {
                if (Schema::hasColumn('task_logistics', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
