<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A project's own lifecycle phases, and the work bucketed under them.
 *
 * The old PLM carried two phase systems — a master catalogue and a per-project
 * plan with sub-phases — for one idea. This is the single per-project model:
 * one row per phase (a sub-phase points at its parent), tasks attach via
 * tasks.phase_id, and a phase's status and % are derived from its tasks, never
 * stored. The nine-stage default (Preparation → Support) is seeded on demand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_phases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('project_phases')->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('slug', 180);
            $table->unsignedSmallInteger('position')->default(0);
            $table->date('due_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'position']);
        });

        // Work attaches to a phase. Nullable: most tasks belong to no phase.
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('phase_id')->nullable()->after('project_id')
                ->constrained('project_phases')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('phase_id');
        });
        Schema::dropIfExists('project_phases');
    }
};
