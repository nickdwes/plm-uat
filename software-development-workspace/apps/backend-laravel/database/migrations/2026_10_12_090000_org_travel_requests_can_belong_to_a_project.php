<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The bridge between project travel and the corporate travel module.
 *
 * Project travel is planned as multi-person cycles on the project; the travel
 * module runs a per-traveller request through approval and booking. Until now
 * the two never met, so project-planned travel was invisible in the module.
 * A travel request now carries the project it serves (so the module can list
 * and filter by project) and the project cycle it was raised from (so raising
 * is idempotent and the project can show what it spawned).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('travel_requests', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('account_id')
                ->constrained('projects')->nullOnDelete();
            $table->foreignId('source_project_travel_request_id')->nullable()->after('project_id')
                ->constrained('project_travel_requests')->nullOnDelete();
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::table('travel_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_project_travel_request_id');
            $table->dropConstrainedForeignId('project_id');
        });
    }
};
