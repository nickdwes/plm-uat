<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expense tracking analytics needs two things the flat spend list never carried:
 * a category, so spend can be compared to budget by category rather than as one
 * lump, and a link back to the task or travel request the money paid for, so an
 * over-run can be traced to the work that caused it.
 *
 * Budget lines gain the same category vocabulary so budget-vs-actual can be cut
 * the same way on both sides. Everything is nullable — existing lines and
 * expenses stay valid and simply read as "uncategorised".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_budget_lines', function (Blueprint $table) {
            $table->string('category', 40)->nullable()->after('label');
        });

        Schema::table('project_expenses', function (Blueprint $table) {
            $table->string('category', 40)->nullable()->after('label');
            $table->foreignId('task_id')->nullable()->after('category')
                ->constrained('tasks')->nullOnDelete();
            $table->foreignId('travel_request_id')->nullable()->after('task_id')
                ->constrained('project_travel_requests')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('travel_request_id');
            $table->dropConstrainedForeignId('task_id');
            $table->dropColumn('category');
        });

        Schema::table('project_budget_lines', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
