<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issues raised on a project, and escalations raised on those issues.
 *
 * The old PLM walked project → tasks → issues → escalations to build its
 * manager dashboard; the new module had projects, tasks and billing
 * milestones but nothing for issues or escalations, so the health picture the
 * dashboard exists to show could not be computed. This adds them.
 *
 * An escalation, as in the old system, is "this issue was raised to person X" —
 * modelled here as columns on the issue (escalated_at / escalated_to) rather
 * than a separate table, because an issue is escalated or it is not; a full
 * escalation history is more than this dashboard needs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();

            $table->string('title', 300);
            $table->text('description')->nullable();
            $table->string('status', 16)->default('open');       // open | in_progress | resolved
            $table->string('severity', 16)->default('medium');    // low | medium | high | critical

            $table->foreignId('raised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();

            // Escalation — an issue is escalated to somebody, or it is not.
            $table->timestamp('escalated_at')->nullable();
            $table->foreignId('escalated_to')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('escalation_reason', 500)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_issues');
    }
};
