<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Governance roles capture more — department/organisation, an assignment window
 * and contact information — so a role entry stands on its own in the new
 * list-and-modal view.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_governance', function (Blueprint $table) {
            if (! Schema::hasColumn('project_governance', 'department')) {
                $table->string('department', 160)->nullable()->after('backup_employee_id');
            }
            if (! Schema::hasColumn('project_governance', 'contact_info')) {
                $table->string('contact_info', 255)->nullable()->after('approval_authority');
            }
            if (! Schema::hasColumn('project_governance', 'start_date')) {
                $table->date('start_date')->nullable()->after('contact_info');
            }
            if (! Schema::hasColumn('project_governance', 'end_date')) {
                $table->date('end_date')->nullable()->after('start_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('project_governance', function (Blueprint $table) {
            foreach (['department', 'contact_info', 'start_date', 'end_date'] as $c) {
                if (Schema::hasColumn('project_governance', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
