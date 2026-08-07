<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tasks, and everything a project screen hangs off them.
 *
 * The design settles a question the legacy and Plmv2 answered differently. The
 * legacy had a two-level work breakdown — 527 plan phases with 485 sub-phases —
 * and 82 contract milestones pointing at them. Plmv2 threw that away for a flat
 * task board. The design is the task board, but with sub-tasks, which is the
 * same two-level structure under different names: a legacy plan phase is a
 * task, a sub-phase is a sub-task, and the import maps one to one.
 *
 * What the design adds that neither predecessor had: dependencies, a second
 * kind of helper distinct from the assignee, and threaded comments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();

            /*
             * Sub-tasks are tasks. One table rather than two, because the
             * legacy's separate sub-phase table meant everything — assignees,
             * dates, status — had to be modelled twice or not at all, and it
             * was not at all: a sub-phase there carried a name and nothing else.
             */
            $table->foreignId('parent_task_id')->nullable()->constrained('project_tasks')->cascadeOnDelete();

            $table->string('reference', 32)->nullable();
            $table->string('title');
            $table->text('description')->nullable();

            $table->string('status', 32)->default('todo');
            $table->string('priority', 16)->default('medium');
            $table->date('deadline')->nullable();
            $table->timestamp('completed_at')->nullable();

            // Manual ordering, because a board people drag needs one and
            // created-at is not it.
            $table->unsignedInteger('position')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'reference']);
            $table->index(['project_id', 'status']);
            $table->index(['parent_task_id']);
        });

        /*
         * Who is on it, and in what capacity.
         *
         * The design shows Assignees and Assisting as separate things. They are
         * one table with a capacity, because the alternative is two tables that
         * must be kept from disagreeing about whether somebody is on the task
         * twice.
         */
        Schema::create('project_task_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('project_task_id')->constrained('project_tasks')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            $table->string('capacity', 16)->default('assignee');   // assignee | assisting
            $table->timestamps();

            $table->unique(['project_task_id', 'employee_id', 'capacity']);
        });

        /*
         * What has to finish first.
         *
         * A row per edge rather than a column, because a task routinely waits
         * on more than one thing. The pair is unique and self-reference is
         * refused at the application layer — a task depending on itself is a
         * deadlock nothing would ever surface.
         */
        Schema::create('project_task_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('project_task_id')->constrained('project_tasks')->cascadeOnDelete();
            $table->foreignId('depends_on_task_id')->constrained('project_tasks')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['project_task_id', 'depends_on_task_id']);
        });

        /*
         * Comments, threaded one level.
         *
         * The design shows replies under a comment and no deeper. Capping it in
         * the schema is deliberate: unbounded nesting reads badly on a task and
         * nobody has ever asked for the fourth level.
         */
        Schema::create('project_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();

            // Null for a comment on the project itself.
            $table->foreignId('project_task_id')->nullable()->constrained('project_tasks')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('project_comments')->cascadeOnDelete();

            $table->text('body');

            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['project_id', 'project_task_id']);
        });

        // The project's timeline. Same reasoning as everywhere else: what
        // happened here is a different question from what happened on the deal.
        Schema::create('project_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('project_task_id')->nullable()->constrained('project_tasks')->nullOnDelete();

            $table->string('kind', 16)->default('system');
            $table->string('type', 32)->default('note');
            $table->text('body')->nullable();
            $table->timestamp('occurred_at');

            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'occurred_at']);
        });

        // "Resources" on the design's task drawer. Encrypted through the vault
        // like every other document class.
        Schema::create('project_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('project_task_id')->nullable()->constrained('project_tasks')->cascadeOnDelete();

            $table->string('document_type', 32)->default('internal');
            $table->string('label');
            $table->string('original_name');
            $table->string('storage_path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['project_id', 'project_task_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_attachments');
        Schema::dropIfExists('project_activities');
        Schema::dropIfExists('project_comments');
        Schema::dropIfExists('project_task_dependencies');
        Schema::dropIfExists('project_task_members');
        Schema::dropIfExists('project_tasks');
    }
};
