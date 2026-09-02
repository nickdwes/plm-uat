<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Direct a task down the org hierarchy: Function › Department › Practice.
 *
 * The first cut of this used a designation ("what the job is") for "function",
 * which is a role, not a place in the structure. The organisation's own shape
 * is a functional unit that holds departments, which hold business units, which
 * hold practices — so a task is aimed at a functional unit, a department, and/or
 * a practice, narrowing as it goes.
 *
 * `designation_id` was added days ago and never carried real data, so it is
 * replaced here rather than left as a dead column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            if (Schema::hasColumn('tasks', 'designation_id')) {
                $table->dropConstrainedForeignId('designation_id');
            }
            if (! Schema::hasColumn('tasks', 'functional_unit_id')) {
                $table->foreignId('functional_unit_id')->nullable()->after('department_id')
                    ->constrained('org_functional_units')->nullOnDelete();
            }
            if (! Schema::hasColumn('tasks', 'practice_id')) {
                $table->foreignId('practice_id')->nullable()->after('functional_unit_id')
                    ->constrained('org_practices')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            if (Schema::hasColumn('tasks', 'practice_id')) {
                $table->dropConstrainedForeignId('practice_id');
            }
            if (Schema::hasColumn('tasks', 'functional_unit_id')) {
                $table->dropConstrainedForeignId('functional_unit_id');
            }
            if (! Schema::hasColumn('tasks', 'designation_id')) {
                $table->foreignId('designation_id')->nullable()->after('department_id')
                    ->constrained('designations')->nullOnDelete();
            }
        });
    }
};
