<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A task can be directed at a department or a job function, not only a person.
 *
 * The individual assignee (`assigned_to`) answers "who", but work is often
 * handed to a team before a name is on it — "Infrastructure", or "whoever holds
 * the Network Engineer function". These two nullable links let a task name that
 * broader owner alongside, or instead of, one individual.
 *
 * `designation_id` is the "function" — a designation states what the job is.
 * Additive: both columns are nullable and default null; nothing existing moves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            if (! Schema::hasColumn('tasks', 'department_id')) {
                $table->foreignId('department_id')->nullable()->after('assigned_to')
                    ->constrained('departments')->nullOnDelete();
            }
            if (! Schema::hasColumn('tasks', 'designation_id')) {
                $table->foreignId('designation_id')->nullable()->after('department_id')
                    ->constrained('designations')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            if (Schema::hasColumn('tasks', 'designation_id')) {
                $table->dropConstrainedForeignId('designation_id');
            }
            if (Schema::hasColumn('tasks', 'department_id')) {
                $table->dropConstrainedForeignId('department_id');
            }
        });
    }
};
