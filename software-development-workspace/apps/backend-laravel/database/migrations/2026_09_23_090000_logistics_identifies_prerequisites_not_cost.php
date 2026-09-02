<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Requirements & Logistics identifies prerequisites, not cost.
 *
 * Cost and lead time are defined in the task configuration, not typed here, so
 * they go. The flags are reworked to the prerequisite set that drives action:
 * asset/equipment and software/license split out from the old "special tools",
 * professional services split out from vendors, and "transport" folded into
 * travel. Approvals stays as its own gate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_logistics', function (Blueprint $table) {
            if (! Schema::hasColumn('task_logistics', 'needs_asset')) {
                $table->boolean('needs_asset')->default(false)->after('needs_accommodation');
            }
            if (! Schema::hasColumn('task_logistics', 'needs_software')) {
                $table->boolean('needs_software')->default(false)->after('needs_asset');
            }
            if (! Schema::hasColumn('task_logistics', 'needs_professional_services')) {
                $table->boolean('needs_professional_services')->default(false)->after('needs_external_vendors');
            }
        });

        // Carry the old "special tools" onto "asset/equipment" so nothing set is lost.
        if (Schema::hasColumn('task_logistics', 'needs_special_tools')) {
            DB::table('task_logistics')->where('needs_special_tools', true)->update(['needs_asset' => true]);
        }

        Schema::table('task_logistics', function (Blueprint $table) {
            foreach (['needs_transport', 'needs_special_tools', 'estimated_cost', 'lead_time_days', 'approval_notes'] as $col) {
                if (Schema::hasColumn('task_logistics', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('task_logistics', function (Blueprint $table) {
            if (! Schema::hasColumn('task_logistics', 'needs_transport')) {
                $table->boolean('needs_transport')->default(false)->after('needs_travel');
            }
            if (! Schema::hasColumn('task_logistics', 'needs_special_tools')) {
                $table->boolean('needs_special_tools')->default(false);
            }
            if (! Schema::hasColumn('task_logistics', 'estimated_cost')) {
                $table->decimal('estimated_cost', 14, 2)->nullable();
            }
            if (! Schema::hasColumn('task_logistics', 'lead_time_days')) {
                $table->unsignedInteger('lead_time_days')->nullable();
            }
            if (! Schema::hasColumn('task_logistics', 'approval_notes')) {
                $table->text('approval_notes')->nullable();
            }
            foreach (['needs_asset', 'needs_software', 'needs_professional_services'] as $col) {
                if (Schema::hasColumn('task_logistics', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
