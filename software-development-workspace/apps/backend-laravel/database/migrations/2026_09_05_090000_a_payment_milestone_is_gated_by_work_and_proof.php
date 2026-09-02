<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What makes a payment milestone billable: the work behind it, and the proof of
 * it.
 *
 * `project_milestone_links` records the tasks and/or phases that must be
 * complete before a milestone is earned — a milestone can hang off several
 * pieces of work, not the single task the legacy column allowed. And a proof
 * document (an acceptance certificate, a signed delivery note) attaches to the
 * milestone so finance has what an invoice needs before it raises one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_milestone_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('milestone_id')->constrained('project_contract_milestones')->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('phase_id')->nullable()->constrained('project_phases')->cascadeOnDelete();
            $table->timestamps();
            $table->index('milestone_id');
        });

        // Proof-of-delivery documents hang off the milestone, reusing the
        // encrypted project attachment store rather than a parallel one.
        Schema::table('project_attachments', function (Blueprint $table) {
            $table->foreignId('milestone_id')->nullable()->after('task_id')
                  ->constrained('project_contract_milestones')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_attachments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('milestone_id');
        });
        Schema::dropIfExists('project_milestone_links');
    }
};
