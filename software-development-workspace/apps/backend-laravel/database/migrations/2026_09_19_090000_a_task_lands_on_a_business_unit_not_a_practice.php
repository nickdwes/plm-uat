<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The org level under a department is the business unit, not the practice.
 *
 * A task is directed Function › Department › Business Unit; the individual is
 * then picked from within that department. `practice_id` was added this session
 * and never carried data, so it is swapped out rather than left behind.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            if (Schema::hasColumn('tasks', 'practice_id')) {
                $table->dropConstrainedForeignId('practice_id');
            }
            if (! Schema::hasColumn('tasks', 'business_unit_id')) {
                $table->foreignId('business_unit_id')->nullable()->after('department_id')
                    ->constrained('org_business_units')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            if (Schema::hasColumn('tasks', 'business_unit_id')) {
                $table->dropConstrainedForeignId('business_unit_id');
            }
            if (! Schema::hasColumn('tasks', 'practice_id')) {
                $table->foreignId('practice_id')->nullable()->after('functional_unit_id')
                    ->constrained('org_practices')->nullOnDelete();
            }
        });
    }
};
