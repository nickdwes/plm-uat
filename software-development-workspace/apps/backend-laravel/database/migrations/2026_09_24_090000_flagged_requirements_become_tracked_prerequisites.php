<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A flagged requirement becomes a tracked prerequisite action.
 *
 * The logistics flags say WHAT a task needs; these rows make each one
 * ACTIONABLE — a checklist item with a status a PM works down before the task
 * starts, generated automatically from the flags. Asset/licence items carry the
 * result of an inventory check; travel items carry a due date for the reminder.
 *
 * This is the "track on the task first" foundation: raising the real
 * cross-module records (asset requests, vendor engagements) hangs off these
 * rows in a later phase, keyed by `linked_type`/`linked_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_prerequisites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->cascadeOnDelete();

            $table->string('type', 40);            // travel, asset, software, procurement, external_vendor, …
            $table->string('label');
            $table->string('status', 20)->default('open');   // open, in_progress, blocked, done
            $table->text('detail')->nullable();     // e.g. the inventory-check result
            $table->boolean('procurement_needed')->default(false);
            $table->date('due_on')->nullable();     // travel: 5 days before task start
            $table->timestamp('reminded_at')->nullable();

            // Where the real downstream record lands, when a later phase raises one.
            $table->nullableMorphs('linked');

            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['task_id', 'type']);
            $table->index(['organization_id', 'status']);
            $table->index(['due_on', 'reminded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_prerequisites');
    }
};
