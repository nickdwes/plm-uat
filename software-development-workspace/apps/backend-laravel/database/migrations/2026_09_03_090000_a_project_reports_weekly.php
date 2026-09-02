<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The weekly report: a published, dated snapshot per project per week, plus the
 * two small fields the report reads from — an OKR flag on a task, and the
 * latest RAG stamped on the project.
 *
 * The old PLM recomputed "this week / last week" live and had a copy-paste bug
 * that made last week always equal this week. Storing one immutable row per
 * week when a PM publishes fixes that at the root: history is what was recorded,
 * not what a formula reconstructs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_weekly_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->date('week_start'); // Monday of the reported week
            $table->string('rag', 6)->default('green'); // green | amber | red
            $table->unsignedTinyInteger('progress_pct')->default(0);
            $table->unsignedInteger('completed_tasks')->default(0);
            $table->unsignedInteger('total_tasks')->default(0);
            $table->unsignedInteger('overdue_tasks')->default(0);
            $table->unsignedInteger('open_issues')->default(0);
            $table->unsignedInteger('open_dependencies')->default(0);
            $table->text('summary')->nullable();      // PM's narrative for the week
            $table->json('notes')->nullable();         // { okrs:[], risks:[], milestones:[] } digest
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One report per project per week — publishing again updates it.
            $table->unique(['project_id', 'week_start']);
        });

        // A task a PM has raised to a weekly objective (OKR) for the report.
        Schema::table('tasks', function (Blueprint $table) {
            $table->boolean('weekly_okr')->default(false)->after('phase_id');
        });

        // The latest published RAG, denormalised onto the project so lists and
        // the dashboard can show it without replaying every snapshot.
        Schema::table('projects', function (Blueprint $table) {
            $table->string('rag', 6)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('rag');
        });
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('weekly_okr');
        });
        Schema::dropIfExists('project_weekly_snapshots');
    }
};
