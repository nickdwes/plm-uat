<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A task can be routed to a team, not only an individual.
 *
 * Support tickets already route to a team or an agent; a task raised off a
 * ticket carries the same routing so the work sits on the right desk — a shared
 * team queue when no single person is named, an individual when one is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('support_team_id')->nullable()->after('assigned_to')
                  ->constrained('support_teams')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('support_team_id');
        });
    }
};
