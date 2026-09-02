<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A risk gains the work it bears on — a task and/or a phase — and a place for
 * the evidence behind it.
 *
 * The association makes a risk answerable ("which part of the project does this
 * threaten?"); the evidence (assessments, correspondence, screenshots) reuses
 * the encrypted project attachment store rather than a parallel one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_risks', function (Blueprint $table) {
            $table->foreignId('task_id')->nullable()->after('project_id')->constrained('tasks')->nullOnDelete();
            $table->foreignId('phase_id')->nullable()->after('task_id')->constrained('project_phases')->nullOnDelete();
        });

        Schema::table('project_attachments', function (Blueprint $table) {
            $table->foreignId('risk_id')->nullable()->after('material_order_id')
                  ->constrained('project_risks')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_attachments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('risk_id');
        });
        Schema::table('project_risks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('task_id');
            $table->dropConstrainedForeignId('phase_id');
        });
    }
};
