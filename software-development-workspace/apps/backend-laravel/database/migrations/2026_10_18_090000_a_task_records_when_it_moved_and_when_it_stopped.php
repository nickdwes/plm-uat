<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two things that happen to a task and left no trace.
 *
 * A deadline is one column. Moving it overwrote the old date, so a task that
 * slipped four times and one that was estimated correctly looked identical
 * afterwards — and the question a delivery review asks is not "when is it due"
 * but "how many times has this moved, and whose fault were the days".
 *
 * A pause is the other half. Work stops constantly while somebody waits on a
 * client signature or a supplier's delivery, and with nowhere to record it the
 * whole wait counts against us. Recording pause and resume is what lets elapsed
 * time be split into time we held and time somebody else did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_deadline_extensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();

            // Nullable: the first date a task ever gets is an extension of nothing.
            $table->date('previous_deadline')->nullable();
            $table->date('new_deadline');

            $table->text('reason');
            $table->text('impact');
            $table->text('dependency')->nullable();
            // One of Task::RESPONSIBILITIES — who the delay belongs to.
            $table->string('responsible_party', 32)->nullable();

            // The issue this raised, where it raised one.
            $table->foreignId('task_issue_id')->nullable()->constrained('task_issues')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'task_id']);
        });

        Schema::create('task_pauses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();

            // Family and the particular wait within it — "pending_customer" and
            // "awaiting_signoff". The family is what reporting groups by.
            $table->string('category', 32);
            $table->string('reason', 48);
            $table->text('note')->nullable();
            $table->date('expected_resume_on')->nullable();
            $table->string('responsible_party', 32)->nullable();

            $table->foreignId('task_issue_id')->nullable()->constrained('task_issues')->nullOnDelete();

            $table->foreignId('paused_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paused_at');
            $table->foreignId('resumed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resumed_at')->nullable();
            $table->text('resume_note')->nullable();
            $table->timestamps();

            // The open pause is looked up on every read of the task.
            $table->index(['task_id', 'resumed_at']);
            $table->index(['organization_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_pauses');
        Schema::dropIfExists('task_deadline_extensions');
    }
};
