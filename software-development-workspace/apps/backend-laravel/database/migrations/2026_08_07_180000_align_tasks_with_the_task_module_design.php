<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the task module's designs need, and the legacy already had.
 *
 * The designs give the task detail eight tabs — Description, Sub-tasks,
 * Dependencies, Assisting, Comments, Resources, Activities, Issues. Six of
 * those are already built. The two that are not turn out to be the two the
 * legacy used hardest and I had not read:
 *
 *   Issues. 242 of them, split 135 unresolved against 104 resolved — a status
 *   that means something, unlike the three dead ones found elsewhere. 164 of
 *   the 242 are High priority, because an issue gets raised when something is
 *   actually blocking. And 212 escalations against 242 issues: 88% of issues
 *   were put in front of a named person, which is why the design has an
 *   escalate toggle sitting right inside the raise-an-issue form.
 *
 *   Resources — which is not a new idea either. The legacy kept two separate
 *   attachment tables: `tasks_attachments` (825 rows, files on the task) and
 *   `tasks_required_attachments` (386, a named requirement with the file that
 *   later satisfied it). The design splits them exactly the same way, files
 *   under Description and requirements under Resources. `task_required_documents`
 *   already is the second one, so Resources needs no new table.
 *
 * Comments, attachments and activities all exist but insist on a project, and
 * three quarters of tasks have none. So project_id goes nullable on all three
 * rather than a second set of near-identical tables being created — which is
 * precisely the mistake the legacy made with its three request tables.
 */
return new class extends Migration
{
    /** Carry a task, but were written when only projects could own one. */
    private const SHARED = ['project_attachments', 'project_comments', 'project_activities'];

    public function up(): void
    {
        foreach (self::SHARED as $table) {
            Schema::table($table, function (Blueprint $t) {
                // A comment on a support task belongs to no project. Keeping
                // this required would have forced a parallel set of tables.
                $t->unsignedBigInteger('project_id')->nullable()->change();
            });
        }

        /*
         * A dependency need not be another task.
         *
         * 172 of the legacy's 203 were free text — "awaiting the client's
         * firewall change window" and the like, which are nobody's task here.
         * Refusing to record those would only mean the blocker goes unwritten,
         * so the task link goes nullable and the note stands on its own.
         *
         * Nullable rather than pointing the row back at its own task, which
         * would have left the task permanently blocked on itself.
         */
        Schema::table('task_dependencies', function (Blueprint $table) {
            $table->unsignedBigInteger('depends_on_task_id')->nullable()->change();
        });

        // The design's comment menu: reply, react, pin. Replies already exist
        // via parent_id; pinning is one column.
        Schema::table('project_comments', function (Blueprint $table) {
            $table->boolean('is_pinned')->default(false)->after('body');
        });

        /*
         * Reactions.
         *
         * One row per person per emoji per comment, so a reaction can be taken
         * back and cannot be double-counted — the unique key is the whole
         * mechanism.
         */
        Schema::create('comment_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('project_comment_id')->constrained('project_comments')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('emoji', 16);
            $table->timestamps();

            $table->unique(['project_comment_id', 'employee_id', 'emoji']);
        });

        /*
         * Issues — something wrong that stops the task, raised against it.
         *
         * Distinct from the task's own status: a task can be In Progress and
         * still carry two unresolved issues, and the legacy's 135 unresolved
         * against 104 resolved is what that looks like over six years.
         *
         * Two statuses only, because that is all the legacy ever used and all
         * the question needs — is it still in the way, or not.
         */
        Schema::create('task_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();

            $table->string('name');
            $table->text('details')->nullable();
            $table->string('priority', 16)->default('high');
            $table->string('status', 16)->default('open');       // open | resolved

            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('raised_by')->nullable()->constrained('employees')->nullOnDelete();

            $table->timestamps();

            $table->index(['task_id', 'status']);
            $table->index(['organization_id', 'status']);
        });

        /*
         * Escalations — putting an issue in front of somebody by name.
         *
         * A table rather than a column on the issue, because the legacy shows
         * 212 escalations against 242 issues and nothing stops the same issue
         * being escalated twice as it climbs. Who it went to and when is the
         * record; overwriting a single column would lose the first person the
         * moment it went past them.
         */
        Schema::create('task_issue_escalations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('task_issue_id')->constrained('task_issues')->cascadeOnDelete();

            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('raised_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->text('note')->nullable();

            $table->timestamps();

            $table->index(['employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_issue_escalations');
        Schema::dropIfExists('task_issues');
        Schema::dropIfExists('comment_reactions');

        Schema::table('project_comments', function (Blueprint $table) {
            $table->dropColumn('is_pinned');
        });

        Schema::table('task_dependencies', function (Blueprint $table) {
            $table->unsignedBigInteger('depends_on_task_id')->nullable(false)->change();
        });

        foreach (self::SHARED as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->unsignedBigInteger('project_id')->nullable(false)->change();
            });
        }
    }
};
