<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery roles become structured teams.
 *
 * The flexible "add any role" list becomes the ten standing delivery teams,
 * each with a description; and a person on a team now carries a lead flag, a
 * capacity allocation and an assignment window — the data resourcing and the
 * later resource-availability checks need.
 *
 * Additive: existing `project_roles` keep `team_key` null (custom roles), and
 * existing members keep null capacity/dates. Nothing is dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_roles', function (Blueprint $table) {
            if (! Schema::hasColumn('project_roles', 'team_key')) {
                $table->string('team_key', 60)->nullable()->after('name');
            }
            if (! Schema::hasColumn('project_roles', 'description')) {
                $table->text('description')->nullable()->after('team_key');
            }
        });

        Schema::table('project_people', function (Blueprint $table) {
            if (! Schema::hasColumn('project_people', 'is_lead')) {
                $table->boolean('is_lead')->default(false)->after('role_kind');
            }
            if (! Schema::hasColumn('project_people', 'capacity_percent')) {
                $table->unsignedSmallInteger('capacity_percent')->nullable()->after('is_lead');
            }
            if (! Schema::hasColumn('project_people', 'assigned_from')) {
                $table->date('assigned_from')->nullable()->after('capacity_percent');
            }
            if (! Schema::hasColumn('project_people', 'assigned_to')) {
                $table->date('assigned_to')->nullable()->after('assigned_from');
            }
        });
    }

    public function down(): void
    {
        Schema::table('project_roles', function (Blueprint $table) {
            foreach (['team_key', 'description'] as $c) {
                if (Schema::hasColumn('project_roles', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
        Schema::table('project_people', function (Blueprint $table) {
            foreach (['is_lead', 'capacity_percent', 'assigned_from', 'assigned_to'] as $c) {
                if (Schema::hasColumn('project_people', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
